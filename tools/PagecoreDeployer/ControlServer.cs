using System;
using System.Collections.Generic;
using System.IO;
using System.Linq;
using System.Net;
using System.Net.Sockets;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using System.Threading;

namespace PagecoreDeployer;

/// <summary>
/// A small HTTP interface to the same settings and actions the window offers, so
/// the deployer can be driven by a script or another tool.
///
/// It is deliberately hemmed in, because what it exposes — stored credentials
/// and a button that overwrites a live website — is worth protecting from
/// anything else running on this machine:
///
///   * bound to 127.0.0.1 only, so nothing off this machine can reach it;
///   * off unless switched on, and the token changes every time it starts;
///   * every request must carry that token in an Authorization header, which
///     also keeps a web page from reaching it: a browser cannot send that header
///     cross-origin without a preflight this server refuses;
///   * requests carrying a browser Origin are rejected outright;
///   * the stored password is never returned, only whether one is set;
///   * commands that overwrite the live site need an explicit confirm flag,
///     standing in for the dialog a person would otherwise see.
///
/// Written on a raw socket rather than HttpListener, which needs a URL
/// reservation and therefore administrator rights on first run.
/// </summary>
public sealed class ControlServer : IDisposable
{
    private readonly Settings _settings;
    private readonly Func<string, JsonElement?, string> _runCommand;
    private readonly Action<string> _log;
    private TcpListener? _listener;
    private Thread? _thread;
    private volatile bool _running;

    public int Port { get; private set; }
    public string Token { get; private set; } = "";
    public string Url => $"http://127.0.0.1:{Port}";

    /// <param name="runCommand">Runs a named command; returns a status string or throws.</param>
    public ControlServer(Settings settings, Func<string, JsonElement?, string> runCommand, Action<string> log)
    {
        _settings = settings;
        _runCommand = runCommand;
        _log = log;
    }

    public void Start(int port)
    {
        Stop();
        Token = Convert.ToHexString(RandomNumberGenerator.GetBytes(24));
        _listener = new TcpListener(IPAddress.Loopback, port);
        _listener.Start();
        Port = ((IPEndPoint)_listener.LocalEndpoint).Port;
        _running = true;
        _thread = new Thread(AcceptLoop) { IsBackground = true, Name = "PagecoreDeployer API" };
        _thread.Start();
    }

    public void Stop()
    {
        _running = false;
        try { _listener?.Stop(); } catch { /* shutting down */ }
        _listener = null;
    }

    public void Dispose() => Stop();

    private void AcceptLoop()
    {
        while (_running)
        {
            TcpClient client;
            try { client = _listener!.AcceptTcpClient(); }
            catch { break; }        // Stop() closed the listener
            try { Handle(client); }
            catch (Exception ex) { _log($"API error: {ex.Message}"); }
            finally { client.Dispose(); }
        }
    }

    // ---- the smallest HTTP that will do -----------------------------------

    private void Handle(TcpClient client)
    {
        client.ReceiveTimeout = client.SendTimeout = 15000;
        using NetworkStream stream = client.GetStream();

        var (method, path, headers, body) = ReadRequest(stream);
        if (method.Length == 0) return;

        // A page in a browser cannot set Authorization cross-origin, but reject
        // anything that smells of one anyway rather than relying on that alone.
        if (headers.ContainsKey("origin"))
        {
            Respond(stream, 403, new { error = "requests from a browser origin are not accepted" });
            return;
        }

        headers.TryGetValue("authorization", out string? auth);
        string presented = auth?.StartsWith("Bearer ", StringComparison.OrdinalIgnoreCase) == true
            ? auth["Bearer ".Length..].Trim() : "";
        if (!FixedTimeEquals(presented, Token))
        {
            Respond(stream, 401, new { error = "a valid Authorization: Bearer <token> header is required" });
            return;
        }

        try { Route(stream, method, path, body); }
        catch (Exception ex) { Respond(stream, 500, new { error = ex.Message }); }
    }

    private void Route(NetworkStream stream, string method, string path, string body)
    {
        switch (method, path)
        {
            case ("GET", "/api/config"):
                Respond(stream, 200, ConfigView());
                return;

            case ("PUT", "/api/config"):
            case ("POST", "/api/config"):
                Respond(stream, 200, ApplyConfig(body));
                return;

            case ("GET", "/api/commands"):
                Respond(stream, 200, new
                {
                    commands = new[]
                    {
                        new { name = "test-connection", destructive = false, description = "log in and list the remote folders" },
                        new { name = "scan",            destructive = false, description = "walk every folder and report suspicious files" },
                        new { name = "upload-engine",   destructive = true,  description = "upload the engine to <remote>/cms" },
                        new { name = "upload-content",  destructive = true,  description = "publish public_html and pagecore-private" },
                        new { name = "reset-password",  destructive = true,  description = "replace the CMS admin password on the host" },
                    },
                    note = "destructive commands require {\"confirm\": true} in the body"
                });
                return;

            case ("GET", "/api/status"):
                Respond(stream, 200, Jobs.Snapshot());
                return;
        }

        if (method == "POST" && path.StartsWith("/api/commands/", StringComparison.Ordinal))
        {
            string name = path["/api/commands/".Length..];
            JsonElement? payload = ParseBody(body);
            try
            {
                string accepted = _runCommand(name, payload);
                Respond(stream, 202, new { started = name, status = accepted });
            }
            catch (Exception ex)
            {
                Respond(stream, 400, new { error = ex.Message });
            }
            return;
        }

        Respond(stream, 404, new { error = "no such endpoint", see = "/api/commands" });
    }

    // ---- configuration ----------------------------------------------------

    private object ConfigView() => new
    {
        host = _settings.Host,
        user = _settings.User,
        engineFolder = _settings.EngineFolder,
        websiteFolder = _settings.WebsiteFolder,
        remoteFolder = _settings.RemoteFolder,
        // Never the password itself, only whether there is one.
        passwordSet = _settings.ProtectedPassword.Length > 0,
        approvedCertificates = _settings.ApprovedCertificates.Keys.ToArray(),
    };

    private object ApplyConfig(string body)
    {
        JsonElement? payload = ParseBody(body);
        if (payload == null) throw new Exception("a JSON object is required");
        JsonElement root = payload.Value;

        var changed = new List<string>();
        if (TryString(root, "host", out string host)) { _settings.Host = host; changed.Add("host"); }
        if (TryString(root, "user", out string user)) { _settings.User = user; changed.Add("user"); }
        if (TryString(root, "engineFolder", out string engine)) { _settings.EngineFolder = engine; changed.Add("engineFolder"); }
        if (TryString(root, "websiteFolder", out string website)) { _settings.WebsiteFolder = website; changed.Add("websiteFolder"); }
        if (TryString(root, "remoteFolder", out string remote)) { _settings.RemoteFolder = remote; changed.Add("remoteFolder"); }
        if (TryString(root, "password", out string password)) { _settings.SetPassword(password); changed.Add("password"); }

        if (changed.Count == 0) throw new Exception("nothing recognised to change");
        _settings.Save();
        _log($"API changed: {string.Join(", ", changed)}");
        return new { changed = changed.ToArray(), config = ConfigView() };
    }

    private static bool TryString(JsonElement root, string name, out string value)
    {
        value = "";
        if (!root.TryGetProperty(name, out JsonElement element) || element.ValueKind != JsonValueKind.String)
            return false;
        value = element.GetString() ?? "";
        return true;
    }

    private static JsonElement? ParseBody(string body)
    {
        if (string.IsNullOrWhiteSpace(body)) return null;
        try { return JsonDocument.Parse(body).RootElement.Clone(); }
        catch { throw new Exception("the request body is not valid JSON"); }
    }

    // ---- job state --------------------------------------------------------

    /// <summary>What the last (or current) command is doing, for polling.</summary>
    public static class Jobs
    {
        private static readonly object Gate = new();
        private static readonly List<string> Lines = new();
        private static string _name = "";
        private static string _state = "idle";
        private static string _error = "";
        private static DateTime _started, _finished;

        public static bool Busy { get { lock (Gate) return _state == "running"; } }

        public static void Begin(string name)
        {
            lock (Gate)
            {
                _name = name; _state = "running"; _error = "";
                _started = DateTime.Now; _finished = default;
                Lines.Clear();
            }
        }

        public static void Append(string line)
        {
            lock (Gate)
            {
                Lines.Add(line);
                if (Lines.Count > 2000) Lines.RemoveRange(0, Lines.Count - 2000);
            }
        }

        public static void End(string? error)
        {
            lock (Gate)
            {
                _state = error == null ? "succeeded" : "failed";
                _error = error ?? "";
                _finished = DateTime.Now;
            }
        }

        public static object Snapshot()
        {
            lock (Gate)
            {
                return new
                {
                    command = _name,
                    state = _state,
                    error = _error,
                    startedAt = _started == default ? null : _started.ToString("o"),
                    finishedAt = _finished == default ? null : _finished.ToString("o"),
                    log = Lines.ToArray(),
                };
            }
        }
    }

    // ---- wire -------------------------------------------------------------

    private static (string Method, string Path, Dictionary<string, string> Headers, string Body)
        ReadRequest(NetworkStream stream)
    {
        var headers = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase);
        string requestLine = ReadLine(stream);
        if (requestLine.Length == 0) return ("", "", headers, "");

        string[] parts = requestLine.Split(' ');
        if (parts.Length < 2) return ("", "", headers, "");
        string method = parts[0];
        string path = parts[1];
        int query = path.IndexOf('?');
        if (query >= 0) path = path[..query];

        for (int i = 0; i < 64; i++)
        {
            string line = ReadLine(stream);
            if (line.Length == 0) break;
            int colon = line.IndexOf(':');
            if (colon > 0) headers[line[..colon].Trim()] = line[(colon + 1)..].Trim();
        }

        string body = "";
        if (headers.TryGetValue("content-length", out string? lengthText)
            && int.TryParse(lengthText, out int length) && length > 0)
        {
            if (length > 65536) throw new Exception("request body too large");
            var buffer = new byte[length];
            int read = 0;
            while (read < length)
            {
                int got = stream.Read(buffer, read, length - read);
                if (got <= 0) break;
                read += got;
            }
            body = Encoding.UTF8.GetString(buffer, 0, read);
        }

        return (method, path, headers, body);
    }

    private static string ReadLine(NetworkStream stream)
    {
        var line = new StringBuilder();
        var one = new byte[1];
        while (line.Length < 8192)
        {
            int got = stream.Read(one, 0, 1);
            if (got <= 0) break;
            if (one[0] == '\n') break;
            if (one[0] != '\r') line.Append((char)one[0]);
        }
        return line.ToString();
    }

    private static void Respond(NetworkStream stream, int status, object payload)
    {
        string json = JsonSerializer.Serialize(payload, new JsonSerializerOptions { WriteIndented = true });
        byte[] bytes = Encoding.UTF8.GetBytes(json);
        var head = new StringBuilder();
        head.Append($"HTTP/1.1 {status} {Reason(status)}\r\n");
        head.Append("Content-Type: application/json; charset=utf-8\r\n");
        head.Append($"Content-Length: {bytes.Length}\r\n");
        head.Append("Cache-Control: no-store\r\n");
        head.Append("X-Content-Type-Options: nosniff\r\n");
        head.Append("Connection: close\r\n\r\n");
        byte[] headBytes = Encoding.ASCII.GetBytes(head.ToString());
        stream.Write(headBytes, 0, headBytes.Length);
        stream.Write(bytes, 0, bytes.Length);
        stream.Flush();
    }

    private static string Reason(int status) => status switch
    {
        200 => "OK", 202 => "Accepted", 400 => "Bad Request", 401 => "Unauthorized",
        403 => "Forbidden", 404 => "Not Found", 409 => "Conflict", _ => "Internal Server Error",
    };

    private static bool FixedTimeEquals(string a, string b)
    {
        byte[] left = Encoding.UTF8.GetBytes(a), right = Encoding.UTF8.GetBytes(b);
        return left.Length == right.Length && CryptographicOperations.FixedTimeEquals(left, right);
    }
}
