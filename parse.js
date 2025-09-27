const { Parser } = require("@dbml/core");
const { readFileSync } = require("fs");

const parsed = Parser.parseDBMLToJSONv2(readFileSync("./sales.dbml").toString());

console.info(JSON.stringify(parsed.tables[0], null, 4))