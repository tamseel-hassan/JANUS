const fs = require('fs');
const path = require('path');

function walkDir(dir, callback) {
  fs.readdirSync(dir).forEach(f => {
    let dirPath = path.join(dir, f);
    let isDirectory = fs.statSync(dirPath).isDirectory();
    isDirectory ? walkDir(dirPath, callback) : callback(path.join(dir, f));
  });
}

function processFile(filePath) {
  if (!filePath.endsWith('.tsx')) return;
  
  let content = fs.readFileSync(filePath, 'utf8');
  let original = content;

  // 1. Remove shadows
  content = content.replace(/\bshadow-(sm|md|lg|xl|2xl|inner|none)\b/g, '');
  content = content.replace(/\bshadow-[a-z]+-[0-9]+\/[0-9]+\b/g, '');
  
  // 2. Remove blur and background orbs
  content = content.replace(/\bblur-(sm|md|lg|xl|2xl|3xl)\b/g, '');
  // Specifically remove those absolute orb divs
  content = content.replace(/<div[^>]*blur-(2|3)xl[^>]*>[\s\S]*?<\/div>/gi, '');

  // 3. Remove hover:scale
  content = content.replace(/\bhover:scale-[0-9]+\b/g, '');

  // 4. Update border radii
  content = content.replace(/\bbg-slate-800\s+rounded-(xl|lg|md)\b/g, 'bg-slate-800 rounded-2xl');
  content = content.replace(/\brounded-(xl|lg|md)\s+bg-slate-800\b/g, 'rounded-2xl bg-slate-800');
  
  // Nested components icon wrappers
  content = content.replace(/\b(w-\d+\s+h-\d+\s+.*?)\brounded-(full|2xl)\b(.*?flex items-center justify-center)\b/g, '$1rounded-xl$3');

  // 5. Typography hierarchy simplification
  content = content.replace(/\btext-(3xl|4xl|5xl|6xl)\b/g, 'text-2xl');

  // Clean up extra spaces in className
  content = content.replace(/className="([^"]+)"/g, (match, p1) => {
    return `className="${p1.replace(/\s+/g, ' ').trim()}"`;
  });

  if (content !== original) {
    fs.writeFileSync(filePath, content, 'utf8');
    console.log('Updated:', filePath);
  }
}

walkDir(path.join(__dirname, 'src'), processFile);
