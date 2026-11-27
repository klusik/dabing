# Third-party notice: lamejs

Project: Dabing
Repository: klusik/dabing
File: docs/third-party/lamejs-notice.md
Module Type: Third-party software notice
Purpose: Record the provenance and license of the self-hosted MP3 encoder.
Responsibilities: Identify the unmodified vendor bundle and ship its license texts.
Author: Rudolf Klusal
License: All rights reserved for this notice; the vendored library remains under LGPL-3.0.

Dabing vendors the pure JavaScript MP3 encoder `lame.all.js` as
[`public/assets/lame-all.js`](../../public/assets/lame-all.js). The bundle is
copied unchanged from the official npm release [`lamejs@1.2.1`](https://www.npmjs.com/package/lamejs/v/1.2.1)
by Alex Zhukov. Its package metadata identifies the license as LGPL-3.0 and the
source repository as [zhuker/lamejs](https://github.com/zhuker/lamejs).

Pinned provenance:

- npm tarball: `https://registry.npmjs.org/lamejs/-/lamejs-1.2.1.tgz`
- npm `gitHead`: `260ecf8a2cf15b97e65442986c5c9149b0be7764`
- Published tarball SHA-1: `0f92d38729213f106d4a19ded20821da7e89c8e4`
- Vendored `lame.all.js` SHA-256: `026bd88846040f357a937cd85821a48492a362eff0812cda734f23fca55fea3b`
- Upstream package notice: [`lamejs-LICENSE.txt`](lamejs-LICENSE.txt)
- Full LGPL text: [`LGPL-3.0.txt`](LGPL-3.0.txt), copied from the [GNU LGPL v3 text](https://www.gnu.org/licenses/lgpl-3.0.txt)
- Incorporated GPL text: [`GPL-3.0.txt`](GPL-3.0.txt), copied from the [GNU GPL v3 text](https://www.gnu.org/licenses/gpl-3.0.txt)

The application loads this bundle as a separate same-origin script from a
Dedicated Worker. The vendor bundle has not been modified. Changes to the
vendored code must be identified and distributed under the LGPL-3.0 terms.