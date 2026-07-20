The featured-image example uses the same storage model as the rest of Pagecore:

1. The upload is a regular file under `uploads/`.
2. The post stores only the image URL in front matter.
3. The listing template reads `cms_posts()` and renders the card image.
4. The post template reads `cms_post()` and renders the article image.

For a large imported site, a migration script can fill the `image` field for thousands of Markdown posts, then Pagecore's generated post index keeps normal listing pages fast.
