



grapejs and ace-builds are using too much memory check all packages and libraries and remove unnesaccary packages.



✅ Next step (if you want to reduce memory even more)
If you want a follow-up improvement beyond just increasing Node memory, I can help you:

analyze what’s contributing most to bundle size
identify large deps you can load lazily or externalize
configure Vite/Rollup to chunk more efficiently
Just say the word and I’ll guide you through bundle analysis.








✅ If you specifically want process.env.SOURCE_MAP (via dotenv)
Vite doesn’t populate process.env by default the way Node scripts do, but you can load .env yourself in vite.config.js:

1. Install dotenv (if not already):
npm install --save-dev dotenv

2. Add to .env:
SOURCE_MAP=true

3. Load it in vite.config.js:
```javascript

import { defineConfig } from 'vite';
import dotenv from 'dotenv';

dotenv.config();

export default defineConfig({
  build: {
    sourcemap: process.env.SOURCE_MAP === 'true',
  },
});


```


✅ Quick check
For Vite you generally must use VITE_* variables (recommended)
If you need process.env.*, load them manually with dotenv as shown above
Let me know which approach you want to keep (Vite style vs manual dotenv) and I can adjust your config for you.






 "build": "cross-env SOURCE_MAP=true NODE_ENV=development NODE_OPTIONS='--max-old-space-size=4096' vite build",
       

       