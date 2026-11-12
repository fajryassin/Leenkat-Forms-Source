module.exports = function (grunt) {
  return {
    utils: {
      files: {
        "build/js/utils.js": "src/js/utils.js",
      },
      options: {
        js: [
          "node_modules/google-closure-library/**.js",
          ...grunt.file.expand([
            "third_party/libphonenumber/javascript/i18n/phonenumbers/**/*.js",
            "!**/demo-compiled.js",
            "!**/metadatafortesting.js",
            "!**/metadatalite.js",
            "!**/regioncodefortesting.js",
            "!**/*_test.js",
          ]),
        ],
        entry_point: "goog:i18n.phonenumbers.demo",
        compilation_level: "ADVANCED_OPTIMIZATIONS",
        output_wrapper: "(function () {%output%})();\nconst globalContext = typeof window !== 'undefined' ? window : typeof global !== 'undefined' ? global : typeof self !== 'undefined' ? self : this;\nconst utils = globalContext.intlTelInputUtilsTemp;\ndelete globalContext.intlTelInputUtilsTemp;\nexport default utils;",
      },
    },
  };
};
