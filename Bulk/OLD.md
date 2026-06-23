# OLD

Snapshot created before the hardening pass requested on 2026-06-23.

Initial local state:

- `Model/DeleteProducts.php` already had local uncommitted changes.
- `Model/Reindex.php` already included `catalog_product_attribute` in the reindex command from the previous pass.
- `Service/ImageBulkDbImportService.php` already ran `indexer:reindex catalog_product_attribute`, cleared image cache, ran `catalog:images:resize`, and flushed cache after image import from the previous pass.
- `../Bulk.zip` existed as an untracked file.

Main pre-hardening risks:

- Destructive Web API routes used `anonymous` resources.
- Magento CLI commands were built in multiple places.
- Image ZIP extraction used `extractTo()` directly.
- Product deletion did not require an explicit confirmation parameter.
- No local static test script existed for this standalone module repository.
