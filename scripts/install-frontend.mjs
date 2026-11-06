import {cp,mkdir} from 'node:fs/promises';
const root=new URL('../',import.meta.url);
await mkdir(new URL('backend/public/app/',root),{recursive:true});
await cp(new URL('frontend/dist/',root),new URL('backend/public/app/',root),{recursive:true});
console.log('Installed compiled frontend into backend/public/app.');
