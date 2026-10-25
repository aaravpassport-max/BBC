'use strict';
/**
 * Extract a top-level function from fno-lab-core.js by start marker (prefix match).
 */
function extractCoreFunction(coreSource, startMarker, endMarker) {
  const start = coreSource.indexOf(startMarker);
  if (start === -1) {
    throw new Error(`could not locate start marker: ${startMarker}`);
  }
  const end = coreSource.indexOf(endMarker, start);
  if (end === -1) {
    throw new Error(`could not locate end marker after: ${startMarker}`);
  }
  return coreSource.slice(start, end + endMarker.length)
    .split('\n')
    .map((l) => (l.startsWith('  ') ? l.slice(2) : l))
    .join('\n');
}

module.exports = { extractCoreFunction };
