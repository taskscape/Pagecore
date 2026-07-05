<?php
require __DIR__ . '/_bootstrap.php';
sample_header('Home');
$news = array_slice(cms_posts('news'), 0, 3);
?>
<main>
  <section class="hero">
    <div class="hero-copy">
      <?= cms_editable('home/hero') ?>
    </div>
    <div class="hero-media" aria-label="CMS content model preview">
      <div class="media-tile media-tile-large">Markdown</div>
      <div class="media-tile">Drafts</div>
      <div class="media-tile">Posts</div>
      <div class="media-tile">Uploads</div>
    </div>
  </section>

  <section class="band">
    <div class="section-heading">
      <p class="eyebrow">Editable fragments</p>
      <h2>Feature blocks, tables, PDFs, and regular page copy</h2>
    </div>
    <div class="prose">
      <?= cms_editable('home/features') ?>
    </div>
  </section>

  <section class="band band-alt" id="inventory">
    <div class="section-heading">
      <p class="eyebrow">Managed posts</p>
      <h2>Latest news from Markdown files</h2>
    </div>
    <?= cms_listing_controls('news') ?>
    <div class="post-grid">
      <?php foreach ($news as $post): ?>
        <?php sample_post_card($post); ?>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="band">
    <div class="section-heading">
      <p class="eyebrow">Uploads and embeds</p>
      <h2>Media inserted from the editor</h2>
    </div>
    <div class="prose">
      <?= cms_editable('home/media') ?>
    </div>
  </section>

  <section class="band band-alt">
    <div class="section-heading">
      <p class="eyebrow">Content inventory</p>
      <h2>Missing Markdown files can be created from the CMS</h2>
    </div>
    <div class="prose">
      <?= cms_editable('home/missing-callout') ?>
    </div>
  </section>
</main>
<?php sample_footer(); ?>
