# 🟧 Aladdin Evergreen Grid

> One Gutenberg block to display **any post type** as a grid — recipes, blog posts, locations, products, FAQs, anything.

[Detailed docs →](DOCS.md)

---

## 🤔 What is this?

A WordPress plugin that adds a **universal content grid block** to the Gutenberg editor.

Pick a post type → pick a taxonomy → drop the block anywhere. Done.

## ✨ What it does

- 📐 1-4 columns
- 🔍 Search box
- 🏷️ Filter buttons
- 📥 Load more
- 🎨 Brand-matched (orange #E85D20, Catamaran headings)
- 🚀 SEO-friendly (first page rendered server-side)
- 📱 Mobile-first
- ♿ Accessibility-first
- ⚡ 12 KB shipped (frontend)

## 📦 Use cases

| Page | What you put in it |
|---|---|
| `/recipe/` | Recipe archive |
| `/blog/` | Blog index |
| `/locations/` | Locations grid |
| Homepage | "Featured recipes" strip |
| `/menu/` | Menu items |
| `/press/` | "In the press" |
| `/faqs/` | FAQ grid |
| `/now-hiring/` | Open positions |

One block. Infinite pages. Forever.

---

## 🚀 Install (3 steps)

1. Clone or download this repo
2. Run `npm install && npm run build`
3. Zip the folder → upload via WP Admin → Plugins → Add New → Upload

Or SFTP the folder to `/wp-content/plugins/`.

## 🛠️ Use

1. Edit any page in WordPress
2. Add block → search "Evergreen"
3. Pick post type + columns + filters
4. Publish

## ⚙️ Tweak it

- 🎨 Change colors → `blocks/src/content-grid/style.scss`
- 🔌 Add a new post-type renderer → `view.js` `renderMeta()`
- 🪝 Add custom fields via filter → `aeg_grid_item_meta`

## 🤖 Built by

Claude + GPT-5.5 + Gemini Pro working as a team.
3-way audit caught 21 issues before v0.2 shipped.

## 🛡️ License

GPL-2.0-or-later. Use freely.

---

**Need more?** [DOCS.md](DOCS.md) has the full technical reference.
