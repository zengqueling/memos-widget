# Memos Widget for WordPress

![Memos Widget Preview](Memos%20Widget.png)

[English](#english) | [中文](#中文)

## English

### Introduction
Memos Widget is a WordPress plugin that allows you to display your latest Memos updates in your WordPress site's sidebar. It's a simple and elegant way to share your thoughts and notes from Memos with your blog visitors.

### Features
- Display latest Memos updates in WordPress sidebar
- Customizable number of posts to display
- Adjustable content length
- Clean and responsive design
- Built-in connection test in the settings page
- Uses the new Memos REST base (`/api/memos`), with automatic fallback to `/api/v1/memos` for older instances

### Installation
1. Download the plugin files
2. Upload the plugin folder to the `/wp-content/plugins/` directory
3. Activate the plugin through the 'Plugins' menu in WordPress
4. Go to 'Appearance' > 'Widgets' and add the 'Memos Widget' to your sidebar

### Configuration
1. **Global Plugin Settings** (`Settings` > `Memos Widget`):
   - **Memos API URL**: Your Memos instance URL (e.g., `https://memo.zengqueling.com`) — root URL only, the plugin appends `/api/memos` itself
   - **Access Token**: Optional. Required only if your instance is not publicly readable (generated under Memos Settings -> Account -> Personal Access Tokens)
2. **Widget Settings** (`Appearance` > `Widgets`):
   - **Title**: The widget title displayed in your sidebar
   - **Number of posts**: How many Memos to display (default: 5)
   - **Content length**: Maximum number of characters to show for each Memo (default: 50)

### API notes (Memos v0.25+)
- New REST base is `/api`: list via `GET /api/memos`, single memo via `GET /api/memos/{id}`.
- All `/api/v1/*` routes are removed and now return **410 Gone**.
- The Connect path `/memos.api.v1.MemoService/CreateMemo` is also gone (410).
- Memo permalink on the web UI is `/{instance}/memos/{uid}`; the widget reads `uid` from `name` (`memos/<uid>`).

### Requirements
- WordPress 5.0 or higher
- PHP 7.0 or higher
- A running Memos instance with accessible API

### License
This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

### Contributing
Contributions are welcome! Feel free to:
1. Fork the repository
2. Create your feature branch
3. Commit your changes
4. Push to the branch
5. Create a new Pull Request

---

## 中文

### 简介
Memos Widget 是一个 WordPress 插件，可以在你的 WordPress 站点边栏显示最新的 Memos 动态。这是一个简单优雅的方式，让你能够与博客访客分享来自 Memos 的想法和笔记。

### 功能特点
- 独立的 WordPress 后台设置页面（设置 -> Memos Widget），统一管理 API 地址与 Token
- 在 WordPress 侧边栏显示最新 Memos 动态
- **适配新版 Memos REST 基址 `/api/memos`**，并自动回退到旧版 `/api/v1/memos`（兼容未升级实例）
- 后台内置「测试连接」，一眼看清当前配置能否取到数据、实际请求的是哪个端点
- 可选 Access Token 鉴权（公开可读实例可留空）
- 摘要自动清理 Markdown 标记（加粗、行内代码、链接、图片、标题符），侧边栏更干净
- 插件升级后自动清空旧缓存，无需等待 5 分钟
- 小工具端精简配置：仅设置标题、显示条数及截取长度
- 清爽响应式设计（含深色模式）

### 安装方法
1. 下载插件文件
2. 将插件文件夹上传到 `/wp-content/plugins/` 目录
3. 在 WordPress 的"插件"菜单中启用插件
4. 进入"设置" > "Memos Widget"配置 API 地址与 Access Token
5. 进入"外观" > "小工具"添加"最新Memos动态"小工具

### 配置说明
1. **插件全局后台**（设置 -> Memos Widget）：
   - **Memos API地址**：你的 Memos 实例根地址（如 `https://memo.zengqueling.com`），插件会自动拼接 `/api/memos`
   - **Access Token（访问令牌）**：可选。实例公开可读时可留空；需要鉴权时在 Memos 后台（设置 -> 账号 -> 个人访问令牌）生成并填入
   - **测试连接**：保存后可一键验证配置
2. **侧边栏小工具**（外观 -> 小工具）：
   - **标题**：小工具显示的组件标题
   - **显示条数**：要显示的 Memos 数量（默认 5 条）
   - **内容截取长度**：每条 Memo 显示的最大字符数（默认 50 字符）

### API 变更说明（v1.6.0）
- 新版 Memos 的 REST 基址改为 `/api`：列表 `GET /api/memos`，单条 `GET /api/memos/{id}`
- `/api/v1/*` 全部废弃，返回 **410 Gone**
- Connect 路径 `/memos.api.v1.MemoService/CreateMemo` 同样返回 **410**，插件不走该链路
- 详情页地址仍为 `/{实例地址}/memos/{uid}`，uid 从返回体的 `name`（`memos/<uid>`）中解析