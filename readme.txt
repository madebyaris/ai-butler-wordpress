=== ABW-AI - Advanced Butler WordPress AI ===
Contributors: madebyaris
Tags: ai, mcp, elementor, chat, automation
Requires at least: 6.9
Tested up to: 6.9
Stable tag: 1.0.0
Requires PHP: 7.4
License: MIT
License URI: https://opensource.org/licenses/MIT

Advanced AI assistant for WordPress with MCP support, Elementor integration, and conversational chat interface.

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
* Generate Elementor layouts
* CSS generation from descriptions
* Color scheme suggestions

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
* Elementor (optional, for design features)

== Installation ==

1. Upload the `abw-ai` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu
3. Go to ABW-AI > Settings to configure your AI provider API key
4. Start using the chat widget or connect via MCP

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

= Is this compatible with Elementor? =

Yes! ABW-AI has deep Elementor integration for managing pages, templates, and generating layouts.

= How is this different from Angie? =

ABW-AI is an open-source alternative that integrates with the WordPress MCP ecosystem and supports multiple AI providers.

== Changelog ==

= 1.0.0 =
* Initial release
* WordPress Abilities API integration
* Multi-provider AI chat (OpenAI, Anthropic)
* 50+ MCP tools for WordPress management
* Elementor design capabilities
* AI writing and SEO tools

== Upgrade Notice ==

= 1.0.0 =
First release of ABW-AI.
