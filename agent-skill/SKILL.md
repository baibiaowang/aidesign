---
name: ai-design
description: Shape a WooCommerce storefront's look through the AI Design MCP server (tools prefixed ai-design-). Use for theme style parameters (colours, fonts, buttons, radius), an announcement bar, floating contact buttons and trust badges, home-page section blocks, small storefront tweaks (free-shipping bar, sale badges, maintenance mode, checkout field visibility), product compare, variation swatches, product add-ons and product video.
---

# Storefront look with AI Design

## Connect
- Same machine: `wp mcp-adapter serve --server=ai-design --user=<shop-manager>` (STDIO).
- Remote (HTTP): `https://SITE/wp-json/ai-design/mcp`, Basic auth with an Application Password.

## Tools
| Tool | What it does |
|---|---|
| `ai-design-design` | Global style parameters (`style`: primary/secondary/font/radius/button_bg/button_text), announcement bar, floating WhatsApp/phone button, trust badges. **Block themes only** for the style part. |
| `ai-design-store-tweaks` | Free-shipping progress bar, sale-badge percent, add-to-cart text, minimum order amount, per-product min/max qty, related-products count, maintenance/coming-soon mode, checkout field visibility, disable XML-RPC, plus `duplicate_product` / `protect_post` / `cron_list` / `health`. |
| `ai-design-home-sections` | Compose home-page sections as core blocks (`build` / `preview` / `list_types`), editable later in the Site Editor. |
| `ai-design-compare` | A compare button + `[hsm_compare]` table. |
| `ai-design-swatches` | Colour/image/button swatches above a variation dropdown. |
| `ai-design-social-proof` | Real urgency / social proof. |
| `ai-design-product-addons` | Paid add-on options on a product. |
| `ai-design-product-video` | One video per product (YouTube/Vimeo/file). |

## Rules
1. Every module is **OFF by default** — turn it on with `set_settings {enabled:true, ...}`.
2. These are **configuration**, not daily operations: set them once. Don't loop on them.
3. You can set parameters but **never inject HTML or code** — all text is sanitized, numbers cast.
4. `home-sections` `build` writes page content — `append` mode needs no confirmation, `replace` does; prefer `preview` first.
5. `design` global styles only apply on **block themes**; on a classic theme use the Customizer.
