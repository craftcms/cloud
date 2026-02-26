const {getConfigs} = require('@craftcms/webpack');

module.exports = getConfigs(
  'packages/cloud-ops/src/web/assets/*/webpack.config.js',
);
