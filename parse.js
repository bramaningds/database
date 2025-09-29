const { Parser } = require("@dbml/core");
const { readFileSync, readdirSync, writeFileSync } = require("fs");

readdirSync(__dirname)
	.filter((filename) => filename.endsWith(".dbml"))
	.forEach((filename) => {
        const json = Parser.parseDBMLToJSONv2(readFileSync(filename).toString());

        console.info(json.tables[0].fields);

		// writeFileSync(`./json/${filename}.json`, JSON.stringify(json, null, 4));
		process.exit();
	});
