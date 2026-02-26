const {getConfig} = require('@craftcms/webpack');
module.exports = getConfig({
  context: __dirname,
  config: {
    entry: {
      Uploader: './Uploader.js',
    },
  },
});
