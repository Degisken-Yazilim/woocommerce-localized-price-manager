export default {
    plugins: {
        '@fullhuman/postcss-purgecss': {
            content: [
                './assets/src/js/**/*.js',
                // The admin screens moved from src/Admin into src/Modules, and
                // this glob was left behind: every class only ever named in PHP
                // — the whole settings grid, the field labels, the tab nav, the
                // ordered provider list — was being purged out of admin.css.
                './src/**/*.php',
            ],
            defaultExtractor: (content) => {
                return content.match(/[\w-/.:]+(?<!:)/g) || [];
            },
            safelist: {
                // PurgeCSS, [dir="rtl"] içindeki override'ları göremiyor
                // Bu yüzden tüm RTL-specific pattern'leri koruyoruz
                standard: [
                    // Grid span classes
                    /^lpm-span-(2|4|5|8|10|12)$/,
                    /^lpm-span-md-(3|4|5|7|8)$/,
                    /^lpm-span-sm-(5|7|8|12)$/,
                    // Grid offset classes
                    /^lpm-offset-4$/,
                    /^lpm-offset-md-5$/,
                    // Spacing utilities (RTL override - ml/mr/pl/pr swap)
                    /^lpm-(ml|mr|pl|pr)-/,
                    // Flex utilities (RTL override - start/end swap)
                    /^lpm-(justify|align|place)-(start|end)$/,
                    // Text utilities (RTL override - left/right swap)
                    /^lpm-text-(left|right)$/,
                    // RTL-specific form overrides
                    /^lpm-(input-group(-(prepend|append))?|input-group-text)$/,
                    /^lpm-(checkbox|radio|file-upload-input)$/,
                    /^lpm-(btn-group|switch(-slider)?)$/,
                    // RTL component overrides
                    /^lpm-(alert(-(close))?|dt-sortable|geo-row-header)$/,
                    /^lpm-(input|select(-sm)?)$/,
                    // Alert component
                    /^lpm-alert(-(close|error|info|message|success|title))?$/,
                    // Badge component
                    /^lpm-badge(-(danger|error|info|notice|secondary|warning))?$/,
                    // Button component
                    /^lpm-btn(-(danger|info|lg|loading|primary|secondary|sm))?$/,
                    // CountryCurrency component
                    /^lpm-cc-((actions?|add(-btn)?|bulk(-(action|apply))?|cancel|checkbox|delete|edit(-currency)?|modal-(active|country|currency)|reset|save|select(-all)?|status(-toggle)?)|datatable-container)$/,
                    // DataTable component
                    /^lpm-dt-((body|error|info|overlay|pagination|per-page(-select)?|reload|search|state(-row)?|table(-wrapper)?|toolbar(-(cell|left|right))?)|sort-(asc|desc|able))$/,
                    // Logs modal component
                    /^lpm-log-modal-(label|pre|row|user-id|value)$/,
                    // Geolocation component
                    /^lpm-geo-((active|available)(-row|-tpl-)?|add(-btn)?|api-key-(input|wrap)|empty-msg|move-(down|up)|order-btns|priority-badge|provider-label|remove-btn|row-header|templates)$/,
                    // Modal component
                    /^lpm-modal(-(body|close|form-footer|header|overlay|title))?$/,
                    // Form & Input
                    /^lpm-(file-upload-input|form-group(-switch)?|form-text|invalid-feedback|valid-feedback)$/,
                    /^lpm-(input|select|radio)(-sm)?$/,
                    /^lpm-switch(-slider)?$/,
                    // Layout
                    /^lpm-(align-center|container|flex|justify-between|grid)$/,
                    /^lpm-mb-3$/,
                    // Table
                    /^lpm-data-table$/,
                    /^lpm-table$/,
                    // Other components
                    /^lpm-(bulk-actions|exportable|feedback|icon-btn|loading-(container|row|spinner|text)|page-btn|pagination)$/,
                    // Specific feature classes
                    /^lpm-(local-pricing-(datatable|metabox|table)|logs-filter|logs-truncate-form)$/,
                    /^lpm-(last-update|log-search|log-view-btn|original-price|settings-backup)$/,
                    // State class'ları (dinamik)
                    /^is-/,
                    /^has-/,
                    // WordPress core
                    /^wp-/,
                ],
            },
            disabled: process.env.NODE_ENV !== 'production',
        },
        autoprefixer: {},
    },
};
