using System;
using System.Collections.Generic;
using System.Net.Security;
using System.Net.Sockets;
using System.Security.Cryptography.X509Certificates;
using System.Text;

namespace PagecoreDeployer;

/// <summary>
/// Looks at the TLS certificate an FTP server presents, before any credential is
/// sent.
///
/// This exists so that a certificate the system cannot verify is a decision
/// rather than a dead end. Shared hosting routinely presents a certificate in the
/// server's own name, or omits the intermediate, and refusing outright would
/// leave no way through; accepting quietly would make the encryption
/// decorative. So the certificate is fetched here, shown in full, and — once the
/// person at the keyboard says yes — remembered by fingerprint, which is then
/// what every later connection is checked against.
///
/// The exchange below is AUTH TLS and the handshake, nothing else. No user name,
/// no password, no file.
/// </summary>
public static class CertificateCheck
{
    public sealed class Result
    {
        public required string Host { get; init; }
        public X509Certificate2? Certificate { get; init; }

        /// <summary>True when the system verified it on its own: chain trusted
        /// and the name matches. Nothing needs approving.</summary>
        public bool Trusted { get; init; }

        /// <summary>Why it did not verify, in the system's words.</summary>
        public string Problems { get; init; } = "";

        /// <summary>Set when the server could not be reached or spoke no TLS.</summary>
        public string? Unreachable { get; init; }

        /// <summary>SHA-256 of the certificate — what approval is recorded against.</summary>
        public string Fingerprint => Certificate?.GetCertHashString(System.Security.Cryptography.HashAlgorithmName.SHA256) ?? "";

        /// <summary>The certificate as it should appear in a log or a dialog.</summary>
        public string Describe()
        {
            if (Certificate == null) return Unreachable ?? "No certificate.";
            var text = new StringBuilder();
            text.AppendLine($"    Issued to  {Certificate.GetNameInfo(X509NameType.SimpleName, false)}");
            text.AppendLine($"    Names      {SubjectNames(Certificate)}");
            text.AppendLine($"    Issued by  {Certificate.GetNameInfo(X509NameType.SimpleName, true)}");
            text.AppendLine($"    Valid      {Certificate.NotBefore:yyyy-MM-dd} to {Certificate.NotAfter:yyyy-MM-dd}");
            text.Append($"    SHA-256    {Fingerprint}");
            return text.ToString();
        }

        private static string SubjectNames(X509Certificate2 certificate)
        {
            foreach (X509Extension extension in certificate.Extensions)
                if (extension.Oid?.Value == "2.5.29.17")     // subjectAltName
                    return extension.Format(false).Replace("DNS Name=", "").Trim();
            return "(none)";
        }
    }

    /// <summary>
    /// Complete an AUTH TLS handshake and report what the server presented.
    /// </summary>
    public static Result Inspect(string hostPort, int timeoutSeconds = 20)
    {
        string host = hostPort;
        int port = 21;
        int colon = hostPort.LastIndexOf(':');
        if (colon > 0 && int.TryParse(hostPort[(colon + 1)..], out int parsed))
        {
            host = hostPort[..colon];
            port = parsed;
        }

        X509Certificate2? presented = null;
        SslPolicyErrors errors = SslPolicyErrors.None;
        string chainProblems = "";

        try
        {
            using var tcp = new TcpClient { ReceiveTimeout = timeoutSeconds * 1000, SendTimeout = timeoutSeconds * 1000 };
            if (!tcp.ConnectAsync(host, port).Wait(TimeSpan.FromSeconds(timeoutSeconds)))
                return new Result { Host = host, Unreachable = $"Could not connect to {host} port {port}." };

            using var network = tcp.GetStream();
            string greeting = ReadReply(network);
            if (!greeting.StartsWith("220", StringComparison.Ordinal))
                return new Result { Host = host, Unreachable = $"Unexpected greeting from {host}: {greeting}" };

            Send(network, "AUTH TLS");
            string auth = ReadReply(network);
            if (!auth.StartsWith("234", StringComparison.Ordinal))
                return new Result
                {
                    Host = host,
                    Unreachable = $"{host} does not offer FTP over TLS: {auth}",
                };

            using var tls = new SslStream(network, leaveInnerStreamOpen: false,
                (_, certificate, chain, policyErrors) =>
                {
                    if (certificate != null) presented = new X509Certificate2(certificate);
                    errors = policyErrors;
                    if (chain != null) chainProblems = ChainProblems(chain);
                    return true;    // report, never decide — that is the user's call
                });
            tls.AuthenticateAsClient(host);
        }
        catch (Exception ex)
        {
            var inner = ex is AggregateException aggregate ? aggregate.GetBaseException() : ex;
            return new Result { Host = host, Certificate = presented, Unreachable = inner.Message };
        }

        return new Result
        {
            Host = host,
            Certificate = presented,
            Trusted = errors == SslPolicyErrors.None,
            Problems = Describe(errors, chainProblems),
        };
    }

    private static string Describe(SslPolicyErrors errors, string chainProblems)
    {
        if (errors == SslPolicyErrors.None) return "";
        var reasons = new List<string>();
        if (errors.HasFlag(SslPolicyErrors.RemoteCertificateNameMismatch))
            reasons.Add("the name in the certificate is not the host being connected to");
        if (errors.HasFlag(SslPolicyErrors.RemoteCertificateChainErrors))
            reasons.Add("the chain to a trusted root could not be completed"
                        + (chainProblems.Length > 0 ? $" ({chainProblems})" : ""));
        if (errors.HasFlag(SslPolicyErrors.RemoteCertificateNotAvailable))
            reasons.Add("the server sent no certificate");
        return string.Join("; ", reasons);
    }

    private static string ChainProblems(X509Chain chain)
    {
        var seen = new List<string>();
        foreach (X509ChainStatus status in chain.ChainStatus)
            if (status.Status != X509ChainStatusFlags.NoError)
                seen.Add(status.Status.ToString());
        return string.Join(", ", seen);
    }

    // ---- the small amount of FTP needed to reach the handshake ------------

    private static void Send(NetworkStream stream, string command)
    {
        byte[] bytes = Encoding.ASCII.GetBytes(command + "\r\n");
        stream.Write(bytes, 0, bytes.Length);
        stream.Flush();
    }

    /// <summary>
    /// Read one FTP reply. A reply may run over several lines, in which case the
    /// first carries a hyphen after the code and the last repeats the code
    /// followed by a space.
    /// </summary>
    private static string ReadReply(NetworkStream stream)
    {
        var reply = new StringBuilder();
        string line;
        do
        {
            line = ReadLine(stream);
            reply.AppendLine(line);
        }
        while (line.Length >= 4 && line[3] == '-');

        string[] lines = reply.ToString().TrimEnd().Split('\n');
        return lines[^1].Trim();
    }

    private static string ReadLine(NetworkStream stream)
    {
        var line = new StringBuilder();
        var one = new byte[1];
        while (stream.Read(one, 0, 1) == 1)
        {
            if (one[0] == '\n') break;
            if (one[0] != '\r') line.Append((char)one[0]);
            if (line.Length > 4096) break;      // a server that will not stop talking
        }
        return line.ToString();
    }
}
