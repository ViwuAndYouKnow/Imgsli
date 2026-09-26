# Imageslider

**A simple before/after image comparison tool for the browser.**

Drag the slider. Zoom in. Pan around. Drop in two images. Share the comparison with a link that automatically expires after 7 days.

![Preview](https://raw.githubusercontent.com/ViwuAndYouKnow/Imgsli/refs/heads/main/Preview.png)

---

## ✨ Features

* 🖼️ **Before / after slider** — smooth `clip-path` comparison
* 📂 **Drag & drop** — drop images anywhere or replace either side individually
* 🔍 **Zoom & pan** — scroll to zoom up to 8× and drag to move around
* ↔️ **Keyboard controls** — use the arrow keys to nudge the slider
* 🔗 **Shareable comparisons** — upload two images and get a unique link
* ⏳ **Automatic expiry** — shared images are removed after 7 days
* 🖼️ **Multiple formats** — PNG, JPG, GIF, WebP, AVIF and BMP
* 📦 **10 MB limit** — maximum size per image
* ⚡ **No mismatched frames** — shared comparisons load both images before displaying them

---

## 🚀 Run it locally

Imageslider requires **PHP 7.x or newer**, the `fileinfo` extension, and write access to the project directory.

```text
index.html
upload.php
LICENSE
sample/
├── before.png
└── after.png
uploadedpics/
comparisons.json
```

`uploadedpics/` and `comparisons.json` are created automatically when needed.

### Using PHP's built-in server

```bash
php -S localhost:8000
```

Then open:

```text
http://localhost:8000
```

For diagnostics:

```text
http://localhost:8000/upload.php?diag=1
```

---

## 🔗 How sharing works

When you click **Create link**, the two images are uploaded to `upload.php`.

The server returns a unique link:

```json
{
  "ok": true,
  "link": "https://host/path/index.html?id=<32-hex>"
}
```

Each comparison gets a random 16-byte ID encoded as hexadecimal.

Shared images are stored as:

```text
<id>_before.<ext>
<id>_after.<ext>
```

The server determines the actual MIME type using PHP's `finfo` rather than trusting the uploaded filename or client-provided MIME type.

### API

**Create a comparison**

```text
POST /upload.php
```

Files:

```text
before
after
```

**Get comparison information**

```text
GET /upload.php?id=<id>
```

Returns the image URLs and expiration time.

**Get an image**

```text
GET /upload.php?id=<id>&img=before
GET /upload.php?id=<id>&img=after
```

Comparisons automatically expire after **7 days**.

---

## 🖼️ Demo images

The default sample comparison uses images sourced from **Unsplash**. They are included only as demonstration content so the application has something to display on first launch.

They are not part of the application's core functionality, and you can replace them with your own images in:

```text
sample/before.png
sample/after.png
```

If distributing the project, make sure the particular images you use are permitted under their applicable Unsplash/license terms and attribution requirements.

---

## 📸 Screenshot

Replace the preview image at the top of this README with a real capture of your instance.

A wide screenshot showing the dark interface with the slider positioned around the middle works best.

---

## 📄 License

**PolyForm Noncommercial 1.0.0**

Free for personal, hobby, research, education, and other non-commercial use. Credit is required.

Commercial / for-profit use requires a separate license from Viwu.

See [`LICENSE`](LICENSE) for the complete license terms.
