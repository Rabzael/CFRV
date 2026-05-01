const fs = require("fs");
const path = require("path");
const sass = require("sass");

module.exports = function (eleventyConfig) {
  eleventyConfig.addPassthroughCopy("static");
  eleventyConfig.addWatchTarget("src/assets/scss");

  eleventyConfig.on("afterBuild", () => {
    const outCssDir = path.join("public", "css");
    fs.mkdirSync(outCssDir, { recursive: true });
    const result = sass.renderSync({
      file: path.join(__dirname, "src/assets/scss/main.scss"),
      outFile: path.join(outCssDir, "style.css"),
      outputStyle: "compressed",
      sourceMap: true
    });
    fs.writeFileSync(path.join(outCssDir, "style.css"), result.css);
    fs.writeFileSync(path.join(outCssDir, "style.css.map"), result.map);
  });

  eleventyConfig.addNunjucksFilter("trimStart", function (value, search) {
    if (typeof value !== "string" || !search) {
      return value;
    }
    return value.startsWith(search) ? value.substring(search.length) : value;
  });

  return {
    dir: {
      input: "src",
      output: "public",
      includes: "_includes",
      data: "_data"
    },
    passthroughFileCopy: true,
    markdownTemplateEngine: "njk",
    htmlTemplateEngine: "njk",
    templateFormats: ["md", "njk", "html"],
    pathPrefix: "/nuovo/"
  };
};
