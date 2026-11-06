import path from 'path';
import { fileURLToPath } from 'url';
import MiniCssExtractPlugin from 'mini-css-extract-plugin';
import CssMinimizerPlugin from 'css-minimizer-webpack-plugin';
import TerserPlugin from 'terser-webpack-plugin';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

export default (env, argv) => {
    const isProduction = argv.mode === 'production';

    return {
        // One bundle per admin screen: PHP enqueues exactly one of them, so a
        // settings tab never downloads another tab's code.
        entry: {
            'admin': './assets/src/js/admin.js',
            'settings-general': './assets/src/js/entries/settings-general.js',
            'settings-exchange-rates': './assets/src/js/entries/settings-exchange-rates.js',
            'settings-geolocation': './assets/src/js/entries/settings-geolocation.js',
            'settings-countries': './assets/src/js/entries/settings-countries.js',
            'product-metabox': './assets/src/js/entries/product-metabox.js',
            'category-metabox': './assets/src/js/entries/category-metabox.js',
        },
        output: {
            path: path.resolve(__dirname, 'assets/dist'),
            filename: 'js/[name].js',
            clean: isProduction || { keep: /^css\// },
        },
        module: {
            rules: [
                {
                    test: /\.js$/,
                    exclude: /node_modules/,
                    use: {
                        loader: 'babel-loader',
                        options: {
                            presets: [
                                ['@babel/preset-env', {
                                    targets: {
                                        browsers: ['>1%', 'not dead'],
                                    },
                                    useBuiltIns: 'usage',
                                    corejs: 3,
                                }],
                            ],
                        },
                    },
                },
                {
                    test: /\.(sa|sc|c)ss$/,
                    use: [
                        isProduction ? MiniCssExtractPlugin.loader : 'style-loader',
                        'css-loader',
                        {
                            loader: 'postcss-loader',
                        },
                        {
                            loader: 'sass-loader',
                            options: {
                                api: 'modern',
                            },
                        },
                    ],
                },
            ],
        },
        plugins: [
            new MiniCssExtractPlugin({
                filename: 'css/[name].css',
            }),
        ],
        optimization: {
            minimize: isProduction,
            minimizer: [
                new TerserPlugin({
                    terserOptions: {
                        format: {
                            comments: false,
                        },
                    },
                    extractComments: false,
                }),
                new CssMinimizerPlugin(),
            ],
            // Each entry must be self-contained: WordPress enqueues a single
            // file per screen, so a shared runtime or vendor chunk would never
            // be loaded and the bundle would fail at runtime.
            splitChunks: {
                chunks: 'async',
            },
            runtimeChunk: false,
        },
        devtool: isProduction ? false : 'source-map',
        performance: {
            hints: isProduction ? 'warning' : false,
            maxEntrypointSize: 512000,
            maxAssetSize: 512000,
        },
        devServer: {
            static: {
                directory: path.join(__dirname, 'assets/dist'),
            },
            compress: true,
            port: 9001,
            hot: true,
        },
        resolve: {
            extensions: ['.js', '.json'],
            alias: {
                '@': path.resolve(__dirname, 'assets/src/js'),
                '@scss': path.resolve(__dirname, 'assets/src/scss'),
            },
        },
        stats: {
            colors: true,
            modules: false,
            children: false,
            chunks: false,
            chunkModules: false,
        },
    };
};
