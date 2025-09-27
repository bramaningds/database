const { Parser } = require("@dbml/core");
const { readFileSync, readdirSync, writeFileSync } = require("fs");

// const parsed = Parser.parseDBMLToJSONv2(readFileSync("./sales.dbml").toString());

// console.info(JSON.stringify(parsed.tables[0], null, 4));

readdirSync(__dirname)
	.filter((filename) => filename.endsWith(".dbml"))
	.forEach((filename) => {
        const json = Parser.parseDBMLToJSONv2(readFileSync(filename).toString());

        console.info(json);

		writeFileSync(`./json/${filename}.json`, JSON.stringify(json, null, 4));
	});
