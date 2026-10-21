# Media providers

All media goes through one service, `App\Services\Media\MediaService`. The rest of the application never calls Cloudinary or
DigitalOcean directly.

```
MediaService  ──►  MediaProvider (interface)
                     ├── CloudinaryMediaProvider      (REST API, signed requests, no SDK)
                     └── DigitalOceanMediaProvider    (S3-compatible "spaces" disk)
```

Operations: `upload`, `delete`, `replace`, `getUrl`. Metadata (provider, path, URL, MIME type, size, width, height, alt text,
folder) is stored in the `media` table. **Binary files are never stored in PostgreSQL.**

## Choosing the provider

```dotenv
MEDIA_DRIVER=cloudinary        # or: spaces        (applies to NEW uploads)
MEDIA_MAX_UPLOAD_KB=5120       # default 5 MB
```

Each media row remembers the provider that stored it, so changing `MEDIA_DRIVER` never breaks existing files: deletion and URL
generation for an old file keep using its original provider. **Keep the old provider's credentials configured** until every file
stored there has been deleted or migrated. (Bulk migration between providers is not implemented.)

## Cloudinary

1. Create a Cloudinary account → *Settings → API Keys* → note the **cloud name**, **API key** and **API secret**.
2. Configure:

   ```dotenv
   MEDIA_DRIVER=cloudinary
   CLOUDINARY_CLOUD_NAME=your-cloud
   CLOUDINARY_API_KEY=123456789012345
   CLOUDINARY_API_SECRET=…
   ```

   (The three separate variables are used; `CLOUDINARY_URL` is not.)
3. Uploads are **signed** server-side (SHA-1 over the sorted parameters + the secret). The secret never leaves the backend and the browser never talks to Cloudinary.
4. Files are stored under `tenants/{tenant-uuid}/{folder}/{uuid}` (the Cloudinary `public_id`). Delivery URLs come back as `https://res.cloudinary.com/<cloud>/image/upload/…`.
5. `MediaService::getUrl($media, ['width' => 400])` builds transformed delivery URLs (`f_auto,q_auto,w_400,c_limit`).
6. Deleting also invalidates the CDN copy (`invalidate=true`); "not found" counts as already deleted.

## DigitalOcean Spaces

1. Create a Space (note its **region**, e.g. `nyc3`, and **name**). Optionally enable the CDN to get a CDN endpoint.
2. *API → Spaces Keys* → generate a key pair.
3. Configure:

   ```dotenv
   MEDIA_DRIVER=spaces
   DIGITALOCEAN_SPACES_KEY=…
   DIGITALOCEAN_SPACES_SECRET=…
   DIGITALOCEAN_SPACES_REGION=nyc3
   DIGITALOCEAN_SPACES_BUCKET=my-space
   DIGITALOCEAN_SPACES_ENDPOINT=https://nyc3.digitaloceanspaces.com
   DIGITALOCEAN_SPACES_CDN_URL=https://my-space.nyc3.cdn.digitaloceanspaces.com   # optional but recommended
   ```
4. The provider uses Laravel's `spaces` filesystem disk (S3 driver; `league/flysystem-aws-s3-v3` is installed). Objects are written
   with `public` visibility under `tenants/{tenant-uuid}/{folder}/{uuid}.{ext}`; if `DIGITALOCEAN_SPACES_CDN_URL` is set, URLs use it.
5. No bucket CORS rule is needed: uploads happen server-side.

## Where images are shown

- The Next.js storefront optimises images from `res.cloudinary.com` and `*.digitaloceanspaces.com` with `next/image`. If you serve media from a
  **custom CDN hostname**, add it to the frontend's `IMAGE_HOSTS` (a **build-time** setting) and rebuild; unknown hosts are still shown, just not optimised.

## Upload rules (enforced server-side)

| Rule | Value |
|---|---|
| Allowed types | JPEG, PNG, WebP, GIF. Both the **extension and the sniffed MIME type** must match. SVG and other active formats are rejected |
| Favicon | PNG, ICO, WebP |
| Size | ≤ 5 MB (`MEDIA_MAX_UPLOAD_KB`) |
| Dimensions | ≤ 8000 × 8000 px |
| Folders | `uploads`, `products`, `categories`, `branding` |
| Tenancy | Files are namespaced by tenant; every media row is tenant-scoped, so one merchant cannot list, edit, replace or delete another's media |

Failure behaviour: a provider error returns `502` and nothing is saved; if the metadata row cannot be saved after a successful upload,
the uploaded file is removed again (no orphans). `replace` uploads the new file first, repoints the row, then deletes the old file.

## Adding another provider

1. Implement `App\Services\Media\MediaProvider` (`type`, `upload`, `delete`, `url`).
2. Add a case to `App\Enums\MediaProviderType` and a branch in `MediaService::provider()`; add its config under `config/media.php`.
3. Test it with `Http::fake()` or `Storage::fake()` like the existing providers (`tests/Feature/Media/MediaServiceTest.php`).

## Not implemented

Image processing is synchronous (no queued resize/optimise job); transformations rely on Cloudinary's delivery-time transforms.
