using System;
using System.Collections.Generic;
using System.Linq;
using System.Text;
using Microsoft.Win32;

namespace PagecoreDeployer;

/// <summary>
/// Reads an FTP login out of Open Salamander's saved bookmarks.
///
/// This exists so that a credential which already works in one of your own
/// programs can reach another without being typed, read aloud, or written down
/// anywhere. The plain text is produced here, handed straight to
/// <see cref="Settings.SetPassword"/> — which encrypts it with DPAPI, tied to
/// this Windows account — and never logged, never shown, never persisted in any
/// other form.
///
/// Only bookmarks Salamander itself stores without a master password can be read.
/// Where a master password is set, the password is AES-encrypted under it, and
/// this deliberately does not attempt to get past that: the whole point of a
/// master password is that nothing reads it without asking the person.
/// </summary>
public static class SalamanderImport
{
    // Salamander's own table, from src/pwdmngr.cpp. The scramble is obfuscation,
    // not encryption — which is exactly why the file it lives in should never be
    // treated as a safe place for a password.
    private static readonly byte[] ScrambleTable =
    {
        0, 223, 235, 233, 240, 185, 88, 102, 22, 130, 27, 53, 79, 125, 66, 201,
        90, 71, 51, 60, 134, 104, 172, 244, 139, 84, 91, 12, 123, 155, 237, 151,
        192, 6, 87, 32, 211, 38, 149, 75, 164, 145, 52, 200, 224, 226, 156, 50,
        136, 190, 232, 63, 129, 209, 181, 120, 28, 99, 168, 94, 198, 40, 238, 112,
        55, 217, 124, 62, 227, 30, 36, 242, 208, 138, 174, 231, 26, 54, 214, 148,
        37, 157, 19, 137, 187, 111, 228, 39, 110, 17, 197, 229, 118, 246, 153, 80,
        21, 128, 69, 117, 234, 35, 58, 67, 92, 7, 132, 189, 5, 103, 10, 15,
        252, 195, 70, 147, 241, 202, 107, 49, 20, 251, 133, 76, 204, 73, 203, 135,
        184, 78, 194, 183, 1, 121, 109, 11, 143, 144, 171, 161, 48, 205, 245, 46,
        31, 72, 169, 131, 239, 160, 25, 207, 218, 146, 43, 140, 127, 255, 81, 98,
        42, 115, 173, 142, 114, 13, 2, 219, 57, 56, 24, 126, 3, 230, 47, 215,
        9, 44, 159, 33, 249, 18, 93, 95, 29, 113, 220, 89, 97, 182, 248, 64,
        68, 34, 4, 82, 74, 196, 213, 165, 179, 250, 108, 254, 59, 14, 236, 175,
        85, 199, 83, 106, 77, 178, 167, 225, 45, 247, 163, 158, 8, 221, 61, 191,
        119, 16, 253, 105, 186, 23, 170, 100, 216, 65, 162, 122, 150, 176, 154, 193,
        206, 222, 188, 152, 210, 243, 96, 41, 86, 180, 101, 177, 166, 141, 212, 116
    };

    private const byte SignatureScrambled = 1;   // readable without a master password
    private const byte SignatureEncrypted = 2;   // AES under the master password

    public sealed record Bookmark(
        string Name, string Address, string User,
        bool EncryptControl, bool EncryptData, bool Anonymous,
        byte[]? StoredPassword)
    {
        public bool HasPassword => StoredPassword is { Length: > 1 };
        public bool NeedsMasterPassword => StoredPassword is { Length: > 0 }
                                           && StoredPassword[0] == SignatureEncrypted;

        public string Describe()
        {
            var parts = new List<string>();
            if (User.Length > 0) parts.Add(User);
            parts.Add(Address);
            string tls = EncryptControl
                ? (EncryptData ? "TLS on control and data" : "TLS on control only")
                : "no TLS";
            parts.Add(tls);
            if (Anonymous) parts.Add("anonymous");
            else if (NeedsMasterPassword) parts.Add("password needs Salamander's master password");
            else if (!HasPassword) parts.Add("no saved password");
            return string.Join("  ·  ", parts);
        }
    }

    /// <summary>Every bookmark Salamander has saved, newest config version first.</summary>
    public static List<Bookmark> ReadBookmarks()
    {
        var bookmarks = new List<Bookmark>();
        using RegistryKey? root = Registry.CurrentUser.OpenSubKey(@"Software\Open Salamander");
        if (root == null) return bookmarks;

        foreach (string version in root.GetSubKeyNames().OrderByDescending(v => v, StringComparer.OrdinalIgnoreCase))
        {
            using RegistryKey? list = root.OpenSubKey($@"{version}\Plugins Configuration\FTP\Bookmarks");
            if (list == null) continue;

            foreach (string entry in list.GetSubKeyNames())
            {
                using RegistryKey? item = list.OpenSubKey(entry);
                if (item == null) continue;
                bookmarks.Add(new Bookmark(
                    Name: item.GetValue("Name") as string ?? "(unnamed)",
                    Address: item.GetValue("Address") as string ?? "",
                    User: item.GetValue("User") as string ?? "",
                    EncryptControl: Convert.ToInt32(item.GetValue("Encrypt Control Connection") ?? 0) == 1,
                    EncryptData: Convert.ToInt32(item.GetValue("Encrypt Data Connection") ?? 0) == 1,
                    Anonymous: Convert.ToInt32(item.GetValue("Anonymous") ?? 0) == 1,
                    StoredPassword: item.GetValue("PasswordS") as byte[]));
            }
            if (bookmarks.Count > 0) break;   // the newest version that has any
        }
        return bookmarks;
    }

    /// <summary>
    /// The plain password for a bookmark. The caller is expected to put it
    /// straight into protected storage and hold no other copy.
    /// </summary>
    public static string ReadPassword(Bookmark bookmark)
    {
        byte[]? stored = bookmark.StoredPassword;
        if (stored is not { Length: > 1 })
            throw new Exception($"\"{bookmark.Name}\" has no saved password.");

        if (stored[0] == SignatureEncrypted)
            throw new Exception(
                $"\"{bookmark.Name}\" is protected by Open Salamander's master password.\n\n"
                + "That password is held only by Salamander, and reading it without asking you "
                + "for the master password would defeat the point of having one. Open the "
                + "bookmark in Salamander instead, or type the password here.");

        if (stored[0] != SignatureScrambled)
            throw new Exception($"\"{bookmark.Name}\" uses an unrecognised password format.");

        return Unscramble(stored.AsSpan(1));
    }

    /// <summary>
    /// The inverse of Salamander's ScramblePassword: a running key over a fixed
    /// substitution table, with the length carried in three digits ahead of the
    /// password and random padding before those.
    /// </summary>
    private static string Unscramble(ReadOnlySpan<byte> scrambled)
    {
        var unscrambleTable = new byte[256];
        for (int i = 0; i < 256; i++) unscrambleTable[ScrambleTable[i]] = (byte)i;

        var plain = new byte[scrambled.Length];
        int last = 31;
        for (int i = 0; i < scrambled.Length; i++)
        {
            int x = unscrambleTable[scrambled[i]] - 1 - (last % 255);
            if (x <= 0) x += 255;
            plain[i] = (byte)x;
            last = (last + x) % 255 + 1;
        }

        // Skip the random padding: it never contains a digit, so the first digit
        // is the start of the three-character length.
        int offset = 0;
        while (offset < plain.Length && (plain[offset] < '0' || plain[offset] > '9')) offset++;
        if (plain.Length - offset < 3) throw new Exception("The saved password could not be read.");

        int length = (plain[offset] - '0') + 10 * (plain[offset + 1] - '0') + 100 * (plain[offset + 2] - '0');
        int total = ((length + 3) / 17) * 17 + 17;

        // Salamander's own consistency check: the declared length has to agree
        // with both the padded total and where the digits were found.
        if (length < 0 || total != plain.Length || total - offset - 3 != length)
            throw new Exception("The saved password could not be read — the stored value looks corrupted.");

        string password = Encoding.Default.GetString(plain, plain.Length - length, length);
        Array.Clear(plain);
        return password;
    }
}
