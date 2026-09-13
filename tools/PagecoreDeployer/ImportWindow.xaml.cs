using System.Collections.Generic;
using System.Linq;
using System.Windows;

namespace PagecoreDeployer;

/// <summary>
/// Picks one of Open Salamander's saved FTP bookmarks. Shows what each one is
/// and how it connects; never shows its password.
/// </summary>
public partial class ImportWindow : Window
{
    private sealed record Row(string Name, string Detail, SalamanderImport.Bookmark Bookmark);

    public SalamanderImport.Bookmark? Chosen { get; private set; }

    public ImportWindow(List<SalamanderImport.Bookmark> bookmarks)
    {
        InitializeComponent();

        // Usable ones first: a bookmark with no readable password is listed so
        // its absence is visible, rather than leaving the user wondering where
        // it went.
        var rows = bookmarks
            .OrderByDescending(b => b.HasPassword && !b.NeedsMasterPassword)
            .Select(b => new Row(b.Name, b.Describe(), b))
            .ToList();

        BookmarkList.ItemsSource = rows;
        BookmarkList.SelectedIndex = 0;

        if (bookmarks.Any(b => b.NeedsMasterPassword))
            HintText.Text = "Some bookmarks are protected by Salamander's master password and cannot be read from here.";
    }

    private void OnAccept(object sender, RoutedEventArgs e)
    {
        if (BookmarkList.SelectedItem is not Row row) return;
        Chosen = row.Bookmark;
        DialogResult = true;
    }
}
