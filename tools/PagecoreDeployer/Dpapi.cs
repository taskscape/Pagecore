using System;
using System.ComponentModel;
using System.Runtime.InteropServices;
using System.Text;

namespace PagecoreDeployer;

/// <summary>
/// Windows DPAPI (CryptProtectData) via P/Invoke, so the remembered password is
/// encrypted at rest and readable only by the same Windows user on this machine.
/// P/Invoke avoids a NuGet dependency so the app builds with no package restore.
/// </summary>
internal static class Dpapi
{
    [StructLayout(LayoutKind.Sequential)]
    private struct DATA_BLOB
    {
        public int cbData;
        public IntPtr pbData;
    }

    [DllImport("crypt32.dll", SetLastError = true)]
    private static extern bool CryptProtectData(
        ref DATA_BLOB pDataIn, string? szDataDescr, IntPtr pOptionalEntropy,
        IntPtr pvReserved, IntPtr pPromptStruct, int dwFlags, out DATA_BLOB pDataOut);

    [DllImport("crypt32.dll", SetLastError = true)]
    private static extern bool CryptUnprotectData(
        ref DATA_BLOB pDataIn, IntPtr ppszDataDescr, IntPtr pOptionalEntropy,
        IntPtr pvReserved, IntPtr pPromptStruct, int dwFlags, out DATA_BLOB pDataOut);

    [DllImport("kernel32.dll")]
    private static extern IntPtr LocalFree(IntPtr hMem);

    private const int CRYPTPROTECT_UI_FORBIDDEN = 0x1;

    public static string Protect(string plain)
    {
        if (string.IsNullOrEmpty(plain)) return "";
        DATA_BLOB inBlob = ToBlob(Encoding.UTF8.GetBytes(plain));
        try
        {
            if (!CryptProtectData(ref inBlob, "PagecoreDeployer", IntPtr.Zero, IntPtr.Zero,
                    IntPtr.Zero, CRYPTPROTECT_UI_FORBIDDEN, out DATA_BLOB outBlob))
                throw new Win32Exception(Marshal.GetLastWin32Error());
            try { return Convert.ToBase64String(FromBlob(outBlob)); }
            finally { LocalFree(outBlob.pbData); }
        }
        finally { Marshal.FreeHGlobal(inBlob.pbData); }
    }

    public static string Unprotect(string protectedBase64)
    {
        if (string.IsNullOrEmpty(protectedBase64)) return "";
        byte[] bytes;
        try { bytes = Convert.FromBase64String(protectedBase64); }
        catch (FormatException) { return ""; }

        DATA_BLOB inBlob = ToBlob(bytes);
        try
        {
            if (!CryptUnprotectData(ref inBlob, IntPtr.Zero, IntPtr.Zero, IntPtr.Zero,
                    IntPtr.Zero, CRYPTPROTECT_UI_FORBIDDEN, out DATA_BLOB outBlob))
                return ""; // wrong user / machine / corrupt — treat as "nothing remembered"
            try { return Encoding.UTF8.GetString(FromBlob(outBlob)); }
            finally { LocalFree(outBlob.pbData); }
        }
        finally { Marshal.FreeHGlobal(inBlob.pbData); }
    }

    private static DATA_BLOB ToBlob(byte[] data)
    {
        IntPtr ptr = Marshal.AllocHGlobal(data.Length);
        Marshal.Copy(data, 0, ptr, data.Length);
        return new DATA_BLOB { cbData = data.Length, pbData = ptr };
    }

    private static byte[] FromBlob(DATA_BLOB blob)
    {
        byte[] data = new byte[blob.cbData];
        Marshal.Copy(blob.pbData, data, 0, blob.cbData);
        return data;
    }
}
