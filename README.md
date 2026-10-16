# AI Design (Storefront Look)

> **用途**：一个 WordPress 插件 = 一个 MCP 服务器，让 AI agent 驱动**店铺外观与前端展示**——主题样式参数（颜色/字体/按钮/圆角）、公告栏、悬浮联系按钮、信任徽章、首页区块、前台小改（免运费进度条/折扣徽章/维护模式）、商品对比、变体色卡、商品附加选项、产品视频。共 **8 个工具**。
> **状态**：开发中（**v1.1.0**）。本地 Docker 真店验证通过；**未发布**到 wordpress.org。
> **配套仓库**：[`baibiaowang/aihand`](https://github.com/baibiaowang/aihand)（运营）· [`baibiaowang/aitools`](https://github.com/baibiaowang/aitools)（基础设施替代）· 本仓（美观）。三者**可共存**（共享 `hsm-core.php`、命名空间 `ai-design/`、option 前缀 `hsm_`）。
> **快速开始**：见 [安装](#安装)。

## 依赖
WordPress 6.9+ · WooCommerce（部分模块需要）· 官方 **MCP Adapter** 插件。

## 安装
同 ai-hand。server 名 `ai-design`，端点 `https://<站点>/wp-json/ai-design/mcp`。

## 工具（8）
| 工具 | 作用 |
|---|---|
| `design` | 全局样式参数（style: 主色/辅色/字体/圆角/按钮）、公告栏、悬浮按钮、信任徽章 |
| `store-tweaks` | 前台小改：免运费进度条、折扣徽章、加购文案、最低下单额、数量上下限、维护模式、结账字段可见性… |
| `home-sections` | 首页区块（用核心区块组合，可在站点编辑器里再编辑）|
| `compare` | 商品对比按钮 + 对比表 |
| `swatches` | 变体色卡（颜色/图片/按钮）|
| `social-proof` | 紧迫感/社会证明 |
| `product-addons` | 商品附加选项（加价项）|
| `product-video` | 商品视频（YouTube/Vimeo/直链）|

## 说明
- 所有模块**默认关闭**，由白名单设置键驱动；AI 能开关和填参数，但**注入不了 HTML 或代码**（文本都 sanitize、数字都 cast）。
- 多数是"配置一次"的功能，不是日常运营动作。
- `design` 的全局样式只对**块主题**生效（经典主题用 Customizer）。
