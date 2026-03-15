=== ABW-AI - Advanced Butler WordPress AI ===
Contributors: madebyaris
Tags: ai, mcp, chat, automation, gutenberg
Requires at least: 6.9
Tested up to: 6.9
Stable tag: 1.0.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Advanced AI assistant for WordPress with MCP support, Gutenberg-aware editing, and a conversational admin chat interface.

== Description ==

ABW-AI (Advanced Butler WordPress AI) is a comprehensive AI assistant that integrates with the WordPress MCP ecosystem and provides an Angie-like conversational interface for managing your WordPress site.

= Key Features =

**Native MCP Integration**
* Registers 50+ abilities with WordPress Abilities API
* Works with any MCP client (Cursor, Claude Desktop, etc.)
* Automatic exposure via WordPress MCP Adapter

**AI Chat Interface**
* Floating chat widget in WordPress admin
* Multi-provider support (OpenAI, Anthropic, custom)
* Context-aware conversations
* Tool execution with natural language

**AI Writing Features**
* Generate blog posts from topics
* Rewrite and improve content
* SEO meta generation
* Content translation

**AI Design Features**
* CSS generation from descriptions
* Color scheme suggestions
* Block editor-aware page assistance

**WordPress Management**
* Posts/Pages CRUD
* Comments moderation
* Plugin management
* Theme switching
* Site settings
* Menu management

= Requirements =

* WordPress 6.9+
* PHP 7.4+
* OpenAI or Anthropic API key (for chat features)
* Optional WooCommerce for product and order tools

== Installation ==

1. Upload the `abw-ai` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu
3. Go to ABW-AI > Settings to configure your AI provider API key
4. Start using the chat widget or connect via MCP

= External services =

ABW-AI connects to third-party AI services only when an administrator configures a provider and intentionally uses AI-powered features such as chat, rewriting, summarization, translation, SEO assistance, or connection testing.

OpenAI:
* Service: `https://api.openai.com/`
* Data sent: user prompts, selected editor context, requested tool arguments, and AI responses
* Purpose: generate or transform content and power AI chat features
* Privacy policy: [https://openai.com/policies/privacy-policy](https://openai.com/policies/privacy-policy)

Anthropic:
* Service: `https://api.anthropic.com/`
* Data sent: user prompts, selected editor context, requested tool arguments, and AI responses
* Purpose: generate or transform content and power AI chat features
* Privacy policy: [https://www.anthropic.com/legal/privacy](https://www.anthropic.com/legal/privacy)

Custom/OpenAI-compatible provider:
* Service: administrator-defined endpoint
* Data sent: user prompts, selected editor context, requested tool arguments, and AI responses
* Purpose: generate or transform content and power AI chat features
* Privacy policy: depends on the provider selected by the site administrator

= MCP Configuration =

For Cursor or other MCP clients, add to your configuration:

```json
{
  "mcpServers": {
    "abw-ai": {
      "command": "node",
      "args": ["/path/to/wp-mcp-server-abw/dist/server.js"],
      "env": {
        "WORDPRESS_URL": "http://your-site.com",
        "ABW_TOKEN": "your-token-here"
      }
    }
  }
}
```

== Frequently Asked Questions ==

= Does this work without an AI API key? =

The MCP integration works without an API key for external clients like Cursor. However, the built-in chat widget requires an OpenAI or Anthropic API key.

= Does this work with the block editor? =

Yes. ABW-AI integrates with the WordPress Block Editor (Gutenberg) and can read editor context, suggest changes, and apply block-aware content actions from the sidebar.

= How is this different from Angie? =

ABW-AI is an open-source alternative that integrates with the WordPress MCP ecosystem and supports multiple AI providers.

== Changelog ==

= 1.0.0 =
* Initial release
* WordPress Abilities API integration
* Multi-provider AI chat (OpenAI, Anthropic)
* 50+ MCP tools for WordPress management
* Gutenberg-aware editor workflow
* AI writing and SEO tools

== Upgrade Notice ==

= 1.0.0 =
First release of ABW-AI.
