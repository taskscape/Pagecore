<?php
require __DIR__ . '/_bootstrap.php';
sample_header('Contact');
?>
<main>
  <section class="page-title">
    <p class="eyebrow">Reusable page regions</p>
    <h1>Contact content is another editable Markdown fragment</h1>
  </section>
  <section class="band">
    <div class="prose">
      <?= cms_editable('contact/body') ?>
    </div>
  </section>
</main>
<?php sample_footer(); ?>
