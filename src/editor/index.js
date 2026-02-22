/**
 * ABW-AI Block Editor Sidebar
 *
 * Entry point: registers a PluginSidebar so the ABW-AI chat assistant
 * is natively integrated into the WordPress block editor.
 *
 * @package ABW_AI
 */

import { registerPlugin } from '@wordpress/plugins';
import { PluginSidebar, PluginSidebarMoreMenuItem } from '@wordpress/editor';
import { Sidebar } from './components/Sidebar';

import './editor.css';

/**
 * ABW AI Editor Plugin component.
 *
 * Renders both the sidebar and its toggle menu item.
 *
 * @return {import('@wordpress/element').WPElement} Plugin elements.
 */
function ABWEditorPlugin() {
	return (
		<>
			<PluginSidebarMoreMenuItem target="abw-ai-editor-sidebar">
				ABW-AI Butler
			</PluginSidebarMoreMenuItem>
			<PluginSidebar
				name="abw-ai-editor-sidebar"
				title="ABW-AI Butler"
				icon={ ABWIcon }
			>
				<Sidebar />
			</PluginSidebar>
		</>
	);
}

/**
 * Custom SVG icon for the sidebar toggle.
 */
const ABWIcon = (
	<svg viewBox="0 0 24 24" width="24" height="24" fill="none" xmlns="http://www.w3.org/2000/svg">
		<path
			d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8z"
			fill="currentColor"
		/>
		<circle cx="9" cy="10" r="1.5" fill="currentColor" />
		<circle cx="15" cy="10" r="1.5" fill="currentColor" />
		<path
			d="M12 17.5c2.33 0 4.31-1.46 5.11-3.5H6.89c.8 2.04 2.78 3.5 5.11 3.5z"
			fill="currentColor"
		/>
	</svg>
);

registerPlugin( 'abw-ai-editor', {
	render: ABWEditorPlugin,
} );
