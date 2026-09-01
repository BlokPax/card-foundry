// Card Foundry PDF combiner — merges per-card PDFs into one document,
// one card per page, in the order given.
//
// Reads a JSON spec from stdin: { "files": ["/a.pdf", …], "output": "/out.pdf" }
// Bundled to combine.bundle.cjs (esbuild) so it runs on the app's Electron
// binary via ELECTRON_RUN_AS_NODE with no node_modules at runtime:
//
//   npx esbuild resources/pdf/combine.mjs --bundle --platform=node \
//     --outfile=resources/pdf/combine.bundle.cjs

import { readFileSync, writeFileSync } from 'node:fs';
import { PDFDocument } from 'pdf-lib';

async function main() {
    const spec = JSON.parse(readFileSync(0, 'utf8'));

    if (!Array.isArray(spec.files) || spec.files.length === 0 || typeof spec.output !== 'string') {
        console.error('combine: expected {files: [...], output: "..."} on stdin');
        process.exit(2);
    }

    const out = await PDFDocument.create();

    for (const file of spec.files) {
        let source;
        try {
            source = await PDFDocument.load(readFileSync(file));
        } catch (e) {
            console.error(`combine: could not read ${file}: ${e.message}`);
            process.exit(1);
        }

        const pages = await out.copyPages(source, source.getPageIndices());
        for (const page of pages) out.addPage(page);
    }

    // No object streams: plain xref keeps the output readable by every
    // prepress tool (object streams are PDF 1.5+ and trip older RIPs).
    writeFileSync(spec.output, await out.save({ useObjectStreams: false }));
    console.log(`combined ${spec.files.length} file(s) -> ${spec.output}`);
}

main().catch((e) => {
    console.error(`combine: ${e.message}`);
    process.exit(1);
});
