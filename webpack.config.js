const {getConfigs} = require('@craftcms/webpack');

module.exports = getConfigs(
  'packages/cloud-ops/src/cloud/web/assets/*/webpack.config.js',
);
