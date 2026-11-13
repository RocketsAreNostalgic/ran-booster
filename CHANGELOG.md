# Changelog

## [1.0.0-beta.45](https://github.com/RocketsAreNostalgic/ran-booster/compare/v1.0.0-beta.44...v1.0.0-beta.45) (2026-11-12)


### Bug Fixes

* **admin:** bundle the design tokens for WordPress 7.0 ([#809](https://github.com/RocketsAreNostalgic/ran-booster/issues/809)) ([73cab96](https://github.com/RocketsAreNostalgic/ran-booster/commit/73cab96a11f21c5505a6f4f749465b72b7c16ad1))
* **admin:** save unchanged repo settings despite rate limits ([#802](https://github.com/RocketsAreNostalgic/ran-booster/issues/802)) ([2a8a0d9](https://github.com/RocketsAreNostalgic/ran-booster/commit/2a8a0d93b3945a2c3e5a30209f6af1c5ccfd5db4))
* **admin:** stop Installation disclosures shifting on mount ([#812](https://github.com/RocketsAreNostalgic/ran-booster/issues/812)) ([2b4b04a](https://github.com/RocketsAreNostalgic/ran-booster/commit/2b4b04aeb03284d96f6628a8697da0ebd89bb5ee))
* **deployment:** log and map branch archive failure causes ([49d8f66](https://github.com/RocketsAreNostalgic/ran-booster/commit/49d8f66661fe2999f87a02ea67e625c1b699686c))
* **deps:** adopt release updater beta.17 ([#814](https://github.com/RocketsAreNostalgic/ran-booster/issues/814)) ([7f82e9a](https://github.com/RocketsAreNostalgic/ran-booster/commit/7f82e9acce626cd964e8aec3c4418beda1e97584))
* **diagnostics:** keep curated guidance and fixed ids past the screen ([54f842c](https://github.com/RocketsAreNostalgic/ran-booster/commit/54f842c13d6a299b0fff77ea37f2edd59e683536))
* **diagnostics:** log and map deployment failure causes ([#860](https://github.com/RocketsAreNostalgic/ran-booster/issues/860)) ([3810022](https://github.com/RocketsAreNostalgic/ran-booster/commit/3810022cc269b7a7d48756efb5ce9098b19144c0))
* **diagnostics:** screen failure text before it reaches sinks ([591420a](https://github.com/RocketsAreNostalgic/ran-booster/commit/591420a5a2a5418031277714bd5cd1136abbfecb))
* **docs:** keep a newly opened article's heading in view ([#813](https://github.com/RocketsAreNostalgic/ran-booster/issues/813)) ([d63773d](https://github.com/RocketsAreNostalgic/ran-booster/commit/d63773d2c3df5a058623eb64f58ef0da1e90abe0))
* **docs:** scope the article re-seat to article headers ([#815](https://github.com/RocketsAreNostalgic/ran-booster/issues/815)) ([76acec3](https://github.com/RocketsAreNostalgic/ran-booster/commit/76acec352b6e9eda3f6f0220a727a965c90f8d57))
* **i18n:** stop translating before init at plugin load ([#803](https://github.com/RocketsAreNostalgic/ran-booster/issues/803)) ([15dd310](https://github.com/RocketsAreNostalgic/ran-booster/commit/15dd310d96264b25dc1e67a41ab4a5c525ee36e2))
* **releases:** clarify the release check and update flow ([#821](https://github.com/RocketsAreNostalgic/ran-booster/issues/821)) ([b9d92c7](https://github.com/RocketsAreNostalgic/ran-booster/commit/b9d92c76a9c87c1552d4b599d4af081f2ae5f286))
* **releases:** exact rejection reasons and picker default ([#835](https://github.com/RocketsAreNostalgic/ran-booster/issues/835)) ([6c4c3d7](https://github.com/RocketsAreNostalgic/ran-booster/commit/6c4c3d79abff8e94623be5374d43b0364dc6ebdd))
* **releases:** keep the rejection detail redirect-safe ([d7dc68b](https://github.com/RocketsAreNostalgic/ran-booster/commit/d7dc68b63d010b53d754c19e1258141a71fe5efa))
* **releases:** make Update now work from the stored offer ([#823](https://github.com/RocketsAreNostalgic/ran-booster/issues/823)) ([38af35a](https://github.com/RocketsAreNostalgic/ran-booster/commit/38af35affd8ff16c30384f551bce6a7b9784b6ed))
* **releases:** name why a published release is rejected ([5331bd1](https://github.com/RocketsAreNostalgic/ran-booster/commit/5331bd108443c8fff9a1fbe63632a3e71e27a2d4)), closes [#826](https://github.com/RocketsAreNostalgic/ran-booster/issues/826)
* **releases:** preselect the installed release in the picker ([96be1ab](https://github.com/RocketsAreNostalgic/ran-booster/commit/96be1ab497acf3e05df724296cf51d3f22b8bc9d)), closes [#820](https://github.com/RocketsAreNostalgic/ran-booster/issues/820)
* **releases:** say why a release check found no release ([#831](https://github.com/RocketsAreNostalgic/ran-booster/issues/831)) ([e72f265](https://github.com/RocketsAreNostalgic/ran-booster/commit/e72f265df1e381c1928ba493c30c96eb9674e2c0))
* **releases:** show an earlier check on the Manage Plugins list ([#824](https://github.com/RocketsAreNostalgic/ran-booster/issues/824)) ([7cf9208](https://github.com/RocketsAreNostalgic/ran-booster/commit/7cf9208c576fdbe54db387e14f4d06fad1a79788))
* **releases:** stop reporting a check that recorded nothing ([#811](https://github.com/RocketsAreNostalgic/ran-booster/issues/811)) ([6253913](https://github.com/RocketsAreNostalgic/ran-booster/commit/6253913127b9f19dbd924c2633f9b457350dd65d))
* **settings:** keep newer scope revisions across a rollback ([#806](https://github.com/RocketsAreNostalgic/ran-booster/issues/806)) ([ed26dd1](https://github.com/RocketsAreNostalgic/ran-booster/commit/ed26dd16c49e6278c8a4cb628707cdea89808791))
* **troubleshooting:** record built-with versions in releases ([#807](https://github.com/RocketsAreNostalgic/ran-booster/issues/807)) ([2713cce](https://github.com/RocketsAreNostalgic/ran-booster/commit/2713cce0b183e21b8c3ef6741aa5c06145e25daf))

## [1.0.0-beta.44](https://github.com/RocketsAreNostalgic/ran-booster/compare/v1.0.0-beta.43...v1.0.0-beta.44) (2026-11-12)


### Features

* **admin:** add shared Card footer band ([6a3045e](https://github.com/RocketsAreNostalgic/ran-booster/commit/6a3045ebea9d7f3ddfb0381d30412d449944964f))
* **admin:** adopt shared Core global Notice envelopes ([#627](https://github.com/RocketsAreNostalgic/ran-booster/issues/627)) ([8a9d591](https://github.com/RocketsAreNostalgic/ran-booster/commit/8a9d59138e1f7cc66a8d9213a9d2395c3a652ad3))
* **admin:** adopt shared Notices across Booster screens ([#627](https://github.com/RocketsAreNostalgic/ran-booster/issues/627)) ([4a7caaf](https://github.com/RocketsAreNostalgic/ran-booster/commit/4a7caaf6092232a9089793852aa173df99a90591))
* **admin:** adopt shared release and repository Notices ([#627](https://github.com/RocketsAreNostalgic/ran-booster/issues/627)) ([bab46e5](https://github.com/RocketsAreNostalgic/ran-booster/commit/bab46e5cb964c5a79d0b126f120d785966a29b4f))
* **admin:** clarify webhook receiver copy and fix action label ([ad6047f](https://github.com/RocketsAreNostalgic/ran-booster/commit/ad6047fa626b097dbe55e512a2d09939b9381729))
* **admin:** confirm data-confirm submit buttons added by a dom swap ([2da3ea5](https://github.com/RocketsAreNostalgic/ran-booster/commit/2da3ea579eabb0298315066cfcb56c65a69f198e))
* **admin:** group webhook operations in four fixed labelled buttons ([b35db24](https://github.com/RocketsAreNostalgic/ran-booster/commit/b35db24ce39a7e3273d364c371c75f3bd7b23318))
* **admin:** preview public UI repository picker ([e0a6506](https://github.com/RocketsAreNostalgic/ran-booster/commit/e0a6506ecb93dd233bc264f11f508a4c83fe5580))
* **admin:** repeat the capture replacement note beside Start new capture ([cc92e91](https://github.com/RocketsAreNostalgic/ran-booster/commit/cc92e9159aec1d7984bc35c3f2d57b3ff540f0fe))
* **admin:** replace receiver security notice with plain guidance copy ([1947ba1](https://github.com/RocketsAreNostalgic/ran-booster/commit/1947ba188d92e23eac0b822a553b0231df4d954a))
* **admin:** show busy labels on slow package form actions ([90f8487](https://github.com/RocketsAreNostalgic/ran-booster/commit/90f8487c4fd43fcb9d2a8cf455afa8aaf9825a01))
* **admin:** show repository detail status as step glyphs with disclosures ([2171ab8](https://github.com/RocketsAreNostalgic/ran-booster/commit/2171ab8be4d73a1f8cd65688248773dd898ddbaf))
* **admin:** split diagnostics form and results into separate cards ([6606782](https://github.com/RocketsAreNostalgic/ran-booster/commit/66067824404ad592658354d42afb4105b0412777))
* **admin:** tabbed webhook receiver card with repository chooser ([50d7768](https://github.com/RocketsAreNostalgic/ran-booster/commit/50d7768a035d5b100ffdebdbca1d9234841b9ac6))
* **admin:** trim Diagnostics copy and add disclosure subline ([6f79735](https://github.com/RocketsAreNostalgic/ran-booster/commit/6f79735e139de77612f9e6c6546d91af46dcc9d6))
* **admin:** use Shell breadcrumbs on package recovery and drop duplicate Back controls ([e57c529](https://github.com/RocketsAreNostalgic/ran-booster/commit/e57c52999f41a6998e4b7c2c109013471d528615)), closes [#654](https://github.com/RocketsAreNostalgic/ran-booster/issues/654)
* **admin:** use Shell breadcrumbs on provider repository detail pages ([c8f11bf](https://github.com/RocketsAreNostalgic/ran-booster/commit/c8f11bffe450a881aace52b7d149daae2a55f05b)), closes [#740](https://github.com/RocketsAreNostalgic/ran-booster/issues/740)
* beta.44 UI consistency, webhook and package screens ([#676](https://github.com/RocketsAreNostalgic/ran-booster/issues/676)) ([93a6a86](https://github.com/RocketsAreNostalgic/ran-booster/commit/93a6a86d7a88bc22ed1289eaa0a5fc4135c933b5))
* **onboarding:** add one-click default storage setup ([2b8508a](https://github.com/RocketsAreNostalgic/ran-booster/commit/2b8508afd45722ec38ac68e3b8a9a2468fdef356))
* **provider:** share delivery notice across repository integration panels ([cc9efce](https://github.com/RocketsAreNostalgic/ran-booster/commit/cc9efced743cd9c650768b24e9105691a0c117d7))
* **releases:** label enabled native update as update to version ([a350a40](https://github.com/RocketsAreNostalgic/ran-booster/commit/a350a40745f63f005e949be58f39c2ffbc6e182d))
* **releases:** show a pending list and right-align update now ([e837983](https://github.com/RocketsAreNostalgic/ran-booster/commit/e8379831a0ea811cede69859c572ed0d75f28a08))
* **releases:** show busy state while switching the release track ([17bc11e](https://github.com/RocketsAreNostalgic/ran-booster/commit/17bc11e133b2aae16d35b5ad58e39fae84b8e2cf))
* **settings:** align switches in a gutter and update status in four columns ([f7d22a2](https://github.com/RocketsAreNostalgic/ran-booster/commit/f7d22a2bf34e192332fa8f6de52ebd47605e17c5)), closes [#738](https://github.com/RocketsAreNostalgic/ran-booster/issues/738)
* share Shell navigation motion across repository views ([829e367](https://github.com/RocketsAreNostalgic/ran-booster/commit/829e3672124a390c816edf4170cf4739f910fb39))
* **transporter:** render package tables as collection frames ([eea62b2](https://github.com/RocketsAreNostalgic/ran-booster/commit/eea62b223d9897f908a4df4a736183ef254e00f3))
* **ui:** add a per-page notice icon template and intent helper ([8d3342e](https://github.com/RocketsAreNostalgic/ran-booster/commit/8d3342e5f0191d43daf2d52613d22d178604afaa)), closes [#758](https://github.com/RocketsAreNostalgic/ran-booster/issues/758)
* **ui:** add a reason tooltip for inactive controls ([57e1f10](https://github.com/RocketsAreNostalgic/ran-booster/commit/57e1f10d6c4dd4a72a0f98ab43a9ba19d2be2f12))
* **ui:** add shared source switch bar to Branch and Releases tabs ([f3bc479](https://github.com/RocketsAreNostalgic/ran-booster/commit/f3bc4796d746b9ef7d0b6c202425c0a8844f77c7))
* **ui:** add tab sections and banner regions to the shared Dialog ([f423219](https://github.com/RocketsAreNostalgic/ran-booster/commit/f4232192e580d2aecd4b90cb2142d22b9ef851a0)), closes [#769](https://github.com/RocketsAreNostalgic/ran-booster/issues/769) [#757](https://github.com/RocketsAreNostalgic/ran-booster/issues/757)
* **ui:** label the link-installed checkbox in bold as already installed ([bfbd9f2](https://github.com/RocketsAreNostalgic/ran-booster/commit/bfbd9f26264da7ee97f7e56e597f9e801f3a5349))
* **ui:** lay out Package operation Updates row, description and notice ([dfaafca](https://github.com/RocketsAreNostalgic/ran-booster/commit/dfaafca4da820b71b5ab234c665bd9d605d4e55d))
* **ui:** link provider problems from the installation card header ([c6fdcc3](https://github.com/RocketsAreNostalgic/ran-booster/commit/c6fdcc351be107f641e9ec5cd73fca0543f0b23a))
* **ui:** make Release readiness the readiness table title with a status badge ([6f059d8](https://github.com/RocketsAreNostalgic/ran-booster/commit/6f059d8224c91f3b62fa791f719fb8a3935fee2e))
* **ui:** make Settings sections collapsible Cards ([cefc94f](https://github.com/RocketsAreNostalgic/ran-booster/commit/cefc94f46d87faafa7cf031178343545342f009a)), closes [#762](https://github.com/RocketsAreNostalgic/ran-booster/issues/762)
* **ui:** move Install actions into the Card footer band ([d6a7811](https://github.com/RocketsAreNostalgic/ran-booster/commit/d6a78119904c9bb781b67660ab66608eaf16acd5))
* **ui:** move Logging capture actions into the Card footer band ([a1daa44](https://github.com/RocketsAreNostalgic/ran-booster/commit/a1daa44a1322008397be67f966e6e2a05e562f4b))
* **ui:** move Support report to a footed Card with preview box ([aefccbb](https://github.com/RocketsAreNostalgic/ran-booster/commit/aefccbbfc6d7e782185fe19baeb08a88d8913078))
* **ui:** move webhook actions into the Card footer band ([fce0180](https://github.com/RocketsAreNostalgic/ran-booster/commit/fce0180c994113cee2b89493b6b69e58d9915283))
* **ui:** move webhook actions into the setup disclosure footer ([257607e](https://github.com/RocketsAreNostalgic/ran-booster/commit/257607e2d3b13a97291c0bd5b5c9f34b9f0d60d3))
* **ui:** real h4 Installation titles, header warnings, ruled headings ([9715522](https://github.com/RocketsAreNostalgic/ran-booster/commit/971552206c6db2c1c5f3fd6c1468770bd558e515))
* **ui:** reference Push-to-Deploy in the Branch card sub-line ([6c5a7f1](https://github.com/RocketsAreNostalgic/ran-booster/commit/6c5a7f1fd22cf754e010d68b74bf72dba9f4a33f))
* **ui:** render Installation sections as expandable Cards ([9b1cd75](https://github.com/RocketsAreNostalgic/ran-booster/commit/9b1cd7548d7653a2b360d92f84fc00d789630e34))
* **ui:** restore the extension details banner and tabs ([866713b](https://github.com/RocketsAreNostalgic/ran-booster/commit/866713bdbd08e8e7de6ccb09501e50c85301738f)), closes [#769](https://github.com/RocketsAreNostalgic/ran-booster/issues/769) [#757](https://github.com/RocketsAreNostalgic/ran-booster/issues/757)
* **ui:** restore the Releases pane strapline and align card copy ([5798d12](https://github.com/RocketsAreNostalgic/ran-booster/commit/5798d120232be0ea53074d59e50a96190464b3ed))
* **ui:** show two-tier publishing status on branch and releases tabs ([a967570](https://github.com/RocketsAreNostalgic/ran-booster/commit/a967570f85d93b14063062acb6c36bd11ebb5ccf))
* **ui:** smooth-scroll documentation links when motion is welcome ([fe6a1d7](https://github.com/RocketsAreNostalgic/ran-booster/commit/fe6a1d7607322022253f09c793fb27452c8053e2)), closes [#768](https://github.com/RocketsAreNostalgic/ran-booster/issues/768)
* **ui:** symmetric Package operation card and shared field label rule ([987f3e8](https://github.com/RocketsAreNostalgic/ran-booster/commit/987f3e82c97e9a093bb9bc9357745a5b97e4dab4))
* **ui:** trim Edit and Add package screen copy ([80d66af](https://github.com/RocketsAreNostalgic/ran-booster/commit/80d66af1e7163dc3746a132c85dc53677875928f))
* **webhook:** add one validate action over check and test ([e8070bf](https://github.com/RocketsAreNostalgic/ran-booster/commit/e8070bf355d1b0c73a2e5a1d4cda2ef371202dcd))
* **webhooks:** add a persistent Manual webhook notice and badge ([7459074](https://github.com/RocketsAreNostalgic/ran-booster/commit/74590741798bf626153f6322a3b52ff985223b46))
* **webhook:** show busy state and place result under the button group ([13b2759](https://github.com/RocketsAreNostalgic/ran-booster/commit/13b275967d50ac157ce5eaf909692d8d313a4de5))
* **webhooks:** show a Manual webhook state from verified deliveries ([f85dc02](https://github.com/RocketsAreNostalgic/ran-booster/commit/f85dc02711f5ff21e1797fd9d91da616f43f9da0))


### Bug Fixes

* **a11y:** add table captions, native page-size submit and dedupe provider table CSS ([562d581](https://github.com/RocketsAreNostalgic/ran-booster/commit/562d581be31d1fc6c0fe5e77fc435ff2e748c538)), closes [#633](https://github.com/RocketsAreNostalgic/ran-booster/issues/633)
* **a11y:** give danger buttons aa contrast from error tokens ([2840c09](https://github.com/RocketsAreNostalgic/ran-booster/commit/2840c097d9a84bec4ce3935101bdee90139150f0)), closes [#763](https://github.com/RocketsAreNostalgic/ran-booster/issues/763)
* **a11y:** give each Activity row disclosure a unique accessible name ([08b200e](https://github.com/RocketsAreNostalgic/ran-booster/commit/08b200e27321a17bb53483dfe85f9ae06743843c)), closes [#728](https://github.com/RocketsAreNostalgic/ran-booster/issues/728)
* **a11y:** give extension card text links a 24px target ([bc5ea5c](https://github.com/RocketsAreNostalgic/ran-booster/commit/bc5ea5cd4b627617e263438ea29f981d0364aec2))
* **a11y:** give standalone text links a 24px target ([34d5d43](https://github.com/RocketsAreNostalgic/ran-booster/commit/34d5d4386d4ec3e6d8b49a01b25f7307c7ce2097)), closes [#728](https://github.com/RocketsAreNostalgic/ran-booster/issues/728)
* **a11y:** make Documentation code blocks keyboard scrollable ([f85fe98](https://github.com/RocketsAreNostalgic/ran-booster/commit/f85fe987b84148e21624e6b45284ae533a4d8f40)), closes [#728](https://github.com/RocketsAreNostalgic/ran-booster/issues/728)
* **a11y:** name the branch source pane by its short heading ([c8f298b](https://github.com/RocketsAreNostalgic/ran-booster/commit/c8f298bdaa8cb9b15ee72f2805df6b4dfcddcf31))
* **a11y:** print nonce fields without duplicate _wpnonce ids ([82c4728](https://github.com/RocketsAreNostalgic/ran-booster/commit/82c4728345d75cae6f37340ef66958e61ca2f810)), closes [#728](https://github.com/RocketsAreNostalgic/ran-booster/issues/728)
* **a11y:** remove nested and duplicate landmarks from admin views ([4585aec](https://github.com/RocketsAreNostalgic/ran-booster/commit/4585aeca0b8642834c4c0b99815cb98fe041af6b))
* **a11y:** render troubleshooting activity as a semantic table ([9df2951](https://github.com/RocketsAreNostalgic/ran-booster/commit/9df2951b3688a6b773052cafd6cd81ea0274af0e)), closes [#633](https://github.com/RocketsAreNostalgic/ran-booster/issues/633)
* **a11y:** size package list filters to their longest option ([a0d3319](https://github.com/RocketsAreNostalgic/ran-booster/commit/a0d33192adb4cf2e12dbbe86070901504d7215b7))
* **admin:** accept the info tone for repository details ([79100a2](https://github.com/RocketsAreNostalgic/ran-booster/commit/79100a278a06788f586755c37158af647a356005))
* **admin:** add provider collection breadcrumbs ([#633](https://github.com/RocketsAreNostalgic/ran-booster/issues/633)) ([b92d0c0](https://github.com/RocketsAreNostalgic/ran-booster/commit/b92d0c0e8d44d1169d0495c2a17e0cf081464584))
* **admin:** adopt public historical repository badges ([#629](https://github.com/RocketsAreNostalgic/ran-booster/issues/629)) ([c77c362](https://github.com/RocketsAreNostalgic/ran-booster/commit/c77c362d35a22f8763d52ed3bec19cfa33e0ffa2))
* **admin:** adopt public repository and release badges ([#629](https://github.com/RocketsAreNostalgic/ran-booster/issues/629)) ([77df1fd](https://github.com/RocketsAreNostalgic/ran-booster/commit/77df1fddd18059f45d2a618c953f836f9fe16466))
* **admin:** align Link installed help with approved vocabulary ([fc1b5e9](https://github.com/RocketsAreNostalgic/ran-booster/commit/fc1b5e9d2736835151a47aa3622649b9c27a9167))
* **admin:** align native credential Card insets ([#633](https://github.com/RocketsAreNostalgic/ran-booster/issues/633)) ([3913e86](https://github.com/RocketsAreNostalgic/ran-booster/commit/3913e864a33a516cca16d03b76ff599f7286fc83))
* **admin:** align native provider Card geometry ([#633](https://github.com/RocketsAreNostalgic/ran-booster/issues/633)) ([25c7153](https://github.com/RocketsAreNostalgic/ran-booster/commit/25c7153f765b8407871174aa87d418d0105862c3))
* **admin:** align repository Card interiors ([#633](https://github.com/RocketsAreNostalgic/ran-booster/issues/633)) ([b3b5d41](https://github.com/RocketsAreNostalgic/ran-booster/commit/b3b5d4124e659384daa08954ea7b970f66a60cc3))
* **admin:** align Troubleshooting cards and navigation ([#736](https://github.com/RocketsAreNostalgic/ran-booster/issues/736)) ([80b1709](https://github.com/RocketsAreNostalgic/ran-booster/commit/80b170900ac543718ff16ddb38e877aaea80abba))
* **admin:** bound quick HTMX saves with a 30 second request timeout ([69bcdd8](https://github.com/RocketsAreNostalgic/ran-booster/commit/69bcdd8c8571c2bb949ea2508782d099941dbf89)), closes [#626](https://github.com/RocketsAreNostalgic/ran-booster/issues/626)
* **admin:** compact spacing in Transporter package details ([fc72865](https://github.com/RocketsAreNostalgic/ran-booster/commit/fc7286530c2a66389e15a010ede94bf455acada0))
* **admin:** deduplicate provider webhook destinations ([#733](https://github.com/RocketsAreNostalgic/ran-booster/issues/733)) ([a0e2831](https://github.com/RocketsAreNostalgic/ran-booster/commit/a0e2831a00ad937a25dbdcc6e13f90271f2b65c9))
* **admin:** end an open Settings Card on the shared block-end inset ([52e13a0](https://github.com/RocketsAreNostalgic/ran-booster/commit/52e13a03122be762fae3ff8fda90419600822515))
* **admin:** enhance explicitly owned off-page Notices ([#627](https://github.com/RocketsAreNostalgic/ran-booster/issues/627)) ([4c11a5f](https://github.com/RocketsAreNostalgic/ran-booster/commit/4c11a5f108dca87b7200df2d5a5f96fb34337c29))
* **admin:** fill the repository webhook setup form across its disclosure body ([2ec9a2f](https://github.com/RocketsAreNostalgic/ran-booster/commit/2ec9a2f0487a21c8e9c6517eef1ba0d5a1823263))
* **admin:** fit the Updates select on phone widths ([61eeab2](https://github.com/RocketsAreNostalgic/ran-booster/commit/61eeab2591fc7cce741bfc5de9e2f8b52806bbb3))
* **admin:** give form controls a 3:1 border from one shared token ([eb41cfc](https://github.com/RocketsAreNostalgic/ran-booster/commit/eb41cfc8eb60a28e718435c1b3b71be96796eb58))
* **admin:** give table sort links and repository names a 24px target ([c048d40](https://github.com/RocketsAreNostalgic/ran-booster/commit/c048d40b3aca2def555519267308c441c93f5b50))
* **admin:** group receiver guidance links ([#735](https://github.com/RocketsAreNostalgic/ran-booster/issues/735)) ([33c661d](https://github.com/RocketsAreNostalgic/ran-booster/commit/33c661d9d32c2f31a531746db3605e270f3ebf6c))
* **admin:** hint that the section navigation scrolls on phones ([2377a69](https://github.com/RocketsAreNostalgic/ran-booster/commit/2377a69322757571d528fce6456d325f467b697d))
* **admin:** inset release workflow body ([#732](https://github.com/RocketsAreNostalgic/ran-booster/issues/732)) ([470f062](https://github.com/RocketsAreNostalgic/ran-booster/commit/470f0628bea33da99d1f205520f397372294c3e0))
* **admin:** keep de-emphasised release and webhook text at AA contrast ([722affc](https://github.com/RocketsAreNostalgic/ran-booster/commit/722affcd15a9907eca48384f9f458615c491c334))
* **admin:** keep payload URL beside clipboard icon ([#623](https://github.com/RocketsAreNostalgic/ran-booster/issues/623)) ([5ec8dee](https://github.com/RocketsAreNostalgic/ran-booster/commit/5ec8deed7dcd1db6dbb0468f43860f759b673c73))
* **admin:** keep provider status cells and dividers continuous ([c9bcc81](https://github.com/RocketsAreNostalgic/ran-booster/commit/c9bcc81a1c082be30906594e58224702f222054a)), closes [#731](https://github.com/RocketsAreNostalgic/ran-booster/issues/731)
* **admin:** keep the Ignores pushes note in the Source value column ([f320807](https://github.com/RocketsAreNostalgic/ran-booster/commit/f320807add08470c08cb537251f5e54132f3c46d))
* **admin:** keep unavailable Reinstall natively disabled until JS guards it ([4baeaf0](https://github.com/RocketsAreNostalgic/ran-booster/commit/4baeaf08c523b415bd9c1a019ed3424edc8b6141))
* **admin:** keep wrapped storage status badge in one shape ([2a81eee](https://github.com/RocketsAreNostalgic/ran-booster/commit/2a81eee44d68d82d51c05a19bb77b93fba0fc987)), closes [#729](https://github.com/RocketsAreNostalgic/ran-booster/issues/729)
* **admin:** let credential search match full kind names again ([5340ee9](https://github.com/RocketsAreNostalgic/ran-booster/commit/5340ee9f1ca5da13b61c173cd4f70b19611b9e71))
* **admin:** let each disclosure host own its margin and keep prominent rules scoped ([8c8cfca](https://github.com/RocketsAreNostalgic/ran-booster/commit/8c8cfcac8b5b032e15e22550f973cacf7c431b7e))
* **admin:** mark new-tab links in documentation with the arrow ([66ecedc](https://github.com/RocketsAreNostalgic/ran-booster/commit/66ecedccf7ac4ddc60463b69aecbdbc77ce4c68d))
* **admin:** match fallback disclosures to the public Disclosure header ([3f6b8ad](https://github.com/RocketsAreNostalgic/ran-booster/commit/3f6b8adb28c0709c3e58f78438bf064b91f24e27)), closes [#611](https://github.com/RocketsAreNostalgic/ran-booster/issues/611)
* **admin:** match Overview button before enhancement ([5846087](https://github.com/RocketsAreNostalgic/ran-booster/commit/5846087e55836b8b5b5a15d36c028df1f636b524))
* **admin:** match the extension logo focus ring to the logo corners ([463f644](https://github.com/RocketsAreNostalgic/ran-booster/commit/463f644dd28410c3a05178c349b0eedf393a419c))
* **admin:** match the storage badge fallback height to the mounted Badge ([8d46e24](https://github.com/RocketsAreNostalgic/ran-booster/commit/8d46e2480edd1b5c160d625aba925c16df3a2ab1)), closes [#670](https://github.com/RocketsAreNostalgic/ran-booster/issues/670)
* **admin:** migrate signed feedback and managed failure notices ([#627](https://github.com/RocketsAreNostalgic/ran-booster/issues/627)) ([e5acea2](https://github.com/RocketsAreNostalgic/ran-booster/commit/e5acea25649447e89da9dd59f6b29d2ec1005c6f))
* **admin:** move provider collection headings above Cards ([#633](https://github.com/RocketsAreNostalgic/ran-booster/issues/633)) ([53d1414](https://github.com/RocketsAreNostalgic/ran-booster/commit/53d1414172edf0e3ae43e0993813143723f37800))
* **admin:** move receiver reachability line into manual setup ([3eab80a](https://github.com/RocketsAreNostalgic/ran-booster/commit/3eab80aec457008cd893c3c6c4dda6a6dabddec8))
* **admin:** move Reinstall reason into a tooltip ([#796](https://github.com/RocketsAreNostalgic/ran-booster/issues/796)) ([5382893](https://github.com/RocketsAreNostalgic/ran-booster/commit/5382893d9dd91a5c8eee794897ac374abf729092))
* **admin:** name package list row links per package ([b012eac](https://github.com/RocketsAreNostalgic/ran-booster/commit/b012eace5ed902886b1bc459e3efc10c8f9c158f))
* **admin:** preserve credential child Notice intent ([#626](https://github.com/RocketsAreNostalgic/ran-booster/issues/626)) ([8d1f9d4](https://github.com/RocketsAreNostalgic/ran-booster/commit/8d1f9d43931c87d7010c2243d419bd723050281a))
* **admin:** preserve Notice fallback without WordPress theme tokens ([515fc83](https://github.com/RocketsAreNostalgic/ran-booster/commit/515fc83cbd8e0dac1c88f4e7bbe1d17a00f92dff))
* **admin:** preserve owned branch failure Notices ([#626](https://github.com/RocketsAreNostalgic/ran-booster/issues/626)) ([843fce2](https://github.com/RocketsAreNostalgic/ran-booster/commit/843fce28f70ae4feb74a30a196045583e125a7d3))
* **admin:** preserve release browser Notice lifecycle ([#626](https://github.com/RocketsAreNostalgic/ran-booster/issues/626)) ([2d4b0df](https://github.com/RocketsAreNostalgic/ran-booster/commit/2d4b0dfe30dbbef1840d1e58074d9548434a1281))
* **admin:** preserve shared Notice in package update summary ([#626](https://github.com/RocketsAreNostalgic/ran-booster/issues/626)) ([e2df7b9](https://github.com/RocketsAreNostalgic/ran-booster/commit/e2df7b958efab43705e84a6c505beb91f34ad807))
* **admin:** preview equal Shell navigation heights ([#628](https://github.com/RocketsAreNostalgic/ran-booster/issues/628)) ([fe1de3f](https://github.com/RocketsAreNostalgic/ran-booster/commit/fe1de3fe97d5dfe6bc73a72f5f25ef197d582a04))
* **admin:** print status badges through StatusBadge so views need no blanket phpcs suppression ([6872755](https://github.com/RocketsAreNostalgic/ran-booster/commit/6872755af473f9ea48f06c3b296cabae6207e7c8))
* **admin:** publish current export Notice intent ([#626](https://github.com/RocketsAreNostalgic/ran-booster/issues/626)) ([f5ed3f3](https://github.com/RocketsAreNostalgic/ran-booster/commit/f5ed3f385d33a0ae3ea13cb87913e304ef6a508b))
* **admin:** raise packages table specificity and stacking breakpoint ([1c5c9ea](https://github.com/RocketsAreNostalgic/ran-booster/commit/1c5c9ea837d989d162ccc42b4b28df0e6050c6f2))
* **admin:** refine webhook link label and placement ([#733](https://github.com/RocketsAreNostalgic/ran-booster/issues/733)) ([98b8c81](https://github.com/RocketsAreNostalgic/ran-booster/commit/98b8c81076532d9ebcb601becd36d93b5851102c))
* **admin:** release owned Notice roots after dismissal ([#626](https://github.com/RocketsAreNostalgic/ran-booster/issues/626)) ([13799c5](https://github.com/RocketsAreNostalgic/ran-booster/commit/13799c5ff6a4d9504e78fd2c01edd4260a6f1ce9))
* **admin:** replace reinstall guidance line with a reason tooltip ([0596894](https://github.com/RocketsAreNostalgic/ran-booster/commit/059689414f414540afe64a21d6998a4713cd6d4f))
* **admin:** restore provider trust disclosure on Diagnostics ([6a54d87](https://github.com/RocketsAreNostalgic/ran-booster/commit/6a54d87acfefb2948b5ccfc9dd2fead1ff3a60e7))
* **admin:** restore public lookup group frame ([#734](https://github.com/RocketsAreNostalgic/ran-booster/issues/734)) ([388ab68](https://github.com/RocketsAreNostalgic/ran-booster/commit/388ab682462a1172ffb296b8637024e4f4f349a8))
* **admin:** restore Troubleshooting top-level navigation ([#737](https://github.com/RocketsAreNostalgic/ran-booster/issues/737)) ([f5b7079](https://github.com/RocketsAreNostalgic/ran-booster/commit/f5b707973c6e0cd44ffd2caecbbc3764a8f668ef))
* **admin:** retain portability apply-result Notice lifecycle ([#626](https://github.com/RocketsAreNostalgic/ran-booster/issues/626)) ([89c1392](https://github.com/RocketsAreNostalgic/ran-booster/commit/89c1392de6c98fa40bcfa20224671c8c4fac2be2))
* **admin:** retain shared Notice in portability preview ([#626](https://github.com/RocketsAreNostalgic/ran-booster/issues/626)) ([cb7739a](https://github.com/RocketsAreNostalgic/ran-booster/commit/cb7739a32aeeee6f33e889983c121e0772b84e13))
* **admin:** retain shared Notice lifecycle in repository picker ([#626](https://github.com/RocketsAreNostalgic/ran-booster/issues/626)) ([03418aa](https://github.com/RocketsAreNostalgic/ran-booster/commit/03418aa7073cc468c5ffba30c005360130a9406c))
* **admin:** reword the locked repository notice copy ([dc6be0c](https://github.com/RocketsAreNostalgic/ran-booster/commit/dc6be0c552267af64c61ee4cb4ebaf495f7a9c88))
* **admin:** share the open header divider across every public disclosure ([d95f7bf](https://github.com/RocketsAreNostalgic/ran-booster/commit/d95f7bf05b80ed4867fb311c61cd49c9971edcd3))
* **admin:** shorten Diagnostics repository help copy ([a0c3436](https://github.com/RocketsAreNostalgic/ran-booster/commit/a0c3436d1884c3ef2d561bb71e640d71f6cf3f7b))
* **admin:** shorten GitHub provider page copy ([7ec8860](https://github.com/RocketsAreNostalgic/ran-booster/commit/7ec886015fce071d4fc09faf41451aa90c998618))
* **admin:** show release workflow as not assessed beside published releases ([0491b9a](https://github.com/RocketsAreNostalgic/ran-booster/commit/0491b9a688ad24ee8c93ab30994160734db660be))
* **admin:** show short management history values as badges ([68efcca](https://github.com/RocketsAreNostalgic/ran-booster/commit/68efcca1fc22888f1d21b1b9cfc915e465252714))
* **admin:** show the first-run package empty state instead of the table ([0c34071](https://github.com/RocketsAreNostalgic/ran-booster/commit/0c34071b3bc7b74b0b8ed05f4c6f2bba0e29f630)), closes [#728](https://github.com/RocketsAreNostalgic/ran-booster/issues/728)
* **admin:** stack repository packages table in narrow containers ([1df9cdd](https://github.com/RocketsAreNostalgic/ran-booster/commit/1df9cdd255f66a7abf5fee24d037c45812cf6dab))
* **admin:** start-align the package install and edit action groups ([0c4a4e2](https://github.com/RocketsAreNostalgic/ran-booster/commit/0c4a4e2998e235ce92ef9ac9d6be62aa444420ad))
* **admin:** start-align the Settings History footer actions ([4d57b44](https://github.com/RocketsAreNostalgic/ran-booster/commit/4d57b44bbfadfffebf1b9f24f1ac01a2727779bb))
* **admin:** unify Installation disclosures and dividers ([#634](https://github.com/RocketsAreNostalgic/ran-booster/issues/634)) ([213af59](https://github.com/RocketsAreNostalgic/ran-booster/commit/213af5956c4259eb22afbd1142a62b58a7c63dd1))
* **admin:** use 'Core updates' as the single name for Booster's own updates ([9c0b901](https://github.com/RocketsAreNostalgic/ran-booster/commit/9c0b901128cd25ae67fd00a817007477597c4803))
* **admin:** use a valid detail tone for an existing release workflow ([24376ce](https://github.com/RocketsAreNostalgic/ran-booster/commit/24376ce276ff71d02f191eccda7251cc4741a2fd))
* **admin:** use intrinsic height for compact disclosures ([#634](https://github.com/RocketsAreNostalgic/ran-booster/issues/634)) ([c003f8f](https://github.com/RocketsAreNostalgic/ran-booster/commit/c003f8fdbcf683d9599a3d14f378378f698967d7))
* **admin:** use Shell breadcrumbs for Troubleshooting ([#737](https://github.com/RocketsAreNostalgic/ran-booster/issues/737)) ([29f5529](https://github.com/RocketsAreNostalgic/ran-booster/commit/29f55290e687a1930cf58fbab27d237de595e403))
* **admin:** use the shared Button height for repository row actions ([71dc240](https://github.com/RocketsAreNostalgic/ran-booster/commit/71dc240018c3d3a0166a6022c3dc67e4e7720681))
* **admin:** use the shared gap below the repository detail heading ([e9a8c65](https://github.com/RocketsAreNostalgic/ran-booster/commit/e9a8c6586d438079f643b47dcff88f72efe4f56e))
* **assets:** keep length units and mask colours, update contract tests ([6873eb6](https://github.com/RocketsAreNostalgic/ran-booster/commit/6873eb6782ed0ab6778787728cb13c6f32f78112))
* **assets:** keep property unit comment inline for contract test ([f8e339d](https://github.com/RocketsAreNostalgic/ran-booster/commit/f8e339dd944f4e5d3673655e2ce52eac819c3898))
* **assets:** resolve eslint and stylelint errors blocking CI ([8cd0d0e](https://github.com/RocketsAreNostalgic/ran-booster/commit/8cd0d0e122a14320787abd88172e0cec24bed44e))
* **docs:** stabilize disclosures and contain code blocks ([#634](https://github.com/RocketsAreNostalgic/ran-booster/issues/634)) ([8f2ff7b](https://github.com/RocketsAreNostalgic/ran-booster/commit/8f2ff7bf728c31063ef75dd15de0956d84a43d35))
* **i18n:** refresh navigation source references ([#633](https://github.com/RocketsAreNostalgic/ran-booster/issues/633)) ([68e91c6](https://github.com/RocketsAreNostalgic/ran-booster/commit/68e91c69e9f452116d1bf2edcd912c877f435a93))
* **i18n:** refresh payload URL clipboard label ([#623](https://github.com/RocketsAreNostalgic/ran-booster/issues/623)) ([9659219](https://github.com/RocketsAreNostalgic/ran-booster/commit/96592192c670c9ef1d1445a4ac55662fc2409d54))
* **i18n:** refresh preview source references and comments ([#634](https://github.com/RocketsAreNostalgic/ran-booster/issues/634)) ([5ed1a3a](https://github.com/RocketsAreNostalgic/ran-booster/commit/5ed1a3adc163a08beacf0085f633080f912ac38d))
* **i18n:** refresh provider collection labels and references ([#633](https://github.com/RocketsAreNostalgic/ran-booster/issues/633)) ([5bf1584](https://github.com/RocketsAreNostalgic/ran-booster/commit/5bf1584a195ae79c49ed89c3fe052c7784939826))
* **i18n:** refresh webhook labels and source references ([#733](https://github.com/RocketsAreNostalgic/ran-booster/issues/733)) ([2c90b7d](https://github.com/RocketsAreNostalgic/ran-booster/commit/2c90b7ddb5647033ce0fe5f6b83cc59e39b406ae))
* **i18n:** scope Installation translator comments ([#634](https://github.com/RocketsAreNostalgic/ran-booster/issues/634)) ([a728355](https://github.com/RocketsAreNostalgic/ran-booster/commit/a7283551cb9947619b05993d2dc959a301295d0d))
* **overview:** show the included with booster origin for bundled providers ([1757c64](https://github.com/RocketsAreNostalgic/ran-booster/commit/1757c6485680f1e3e16871b492f8e6dffb4b5a34))
* **packages:** keep Advanced settings anchor after mounting ([9aa8b40](https://github.com/RocketsAreNostalgic/ran-booster/commit/9aa8b403e8c83d34558713ec78fba6dc743eb6b3))
* **packages:** shorten the summary repository link text ([4cfd848](https://github.com/RocketsAreNostalgic/ran-booster/commit/4cfd848061d89bf88eb6d47e50378d688575b1ac))
* **releases:** align use releases button with disclosure buttons ([47ad27b](https://github.com/RocketsAreNostalgic/ran-booster/commit/47ad27b509fdda6b95c7c9c0c2dd06a25f336bea))
* **releases:** move use-releases action below the release track disclosure ([279609c](https://github.com/RocketsAreNostalgic/ran-booster/commit/279609ce0a0d24a4033a00faad3af1716e850826))
* **releases:** place release status notice under the operation notice ([b319cd6](https://github.com/RocketsAreNostalgic/ran-booster/commit/b319cd6e2a5be352b19cef2ffce39022ca1b0dea))
* **releases:** right-align refresh releases on narrow screens ([b83b967](https://github.com/RocketsAreNostalgic/ran-booster/commit/b83b9678def2e07db5f2938c9d3de9efbbf1d069))
* **releases:** right-align use releases button in action row ([5e4bddf](https://github.com/RocketsAreNostalgic/ran-booster/commit/5e4bddfbfbda77053c19d2892ac190ac423d3fa9))
* **releases:** show the branch release status row as neutral ([6fd96d8](https://github.com/RocketsAreNostalgic/ran-booster/commit/6fd96d8aff216baeee2752b5a88b8ef1688dccb9))
* **releases:** use the card gap above the use-releases row ([4367460](https://github.com/RocketsAreNostalgic/ran-booster/commit/43674607ee490ad20ea496633b937db7f795f822))
* **repositories:** place secondary links inside their status Cards ([71be6c8](https://github.com/RocketsAreNostalgic/ran-booster/commit/71be6c87096cfcca92e6d6fa6516d6e46905278e)), closes [#747](https://github.com/RocketsAreNostalgic/ran-booster/issues/747)
* **settings:** describe storage path selection without guessing ([acfd7df](https://github.com/RocketsAreNostalgic/ran-booster/commit/acfd7df43a3d9063442c6d1f1a349f6bc96adbee)), closes [#739](https://github.com/RocketsAreNostalgic/ran-booster/issues/739)
* **settings:** make the numeric status screen-reader-only ([eadb983](https://github.com/RocketsAreNostalgic/ran-booster/commit/eadb983793e8e72f0451b147649bfd53b23965b3)), closes [#611](https://github.com/RocketsAreNostalgic/ran-booster/issues/611)
* **settings:** match fallback switch and badge to the public components ([cd8d793](https://github.com/RocketsAreNostalgic/ran-booster/commit/cd8d793092119fc506e70bb96c1fd87375942860)), closes [#611](https://github.com/RocketsAreNostalgic/ran-booster/issues/611)
* **settings:** move the fallback switch knob shadow to a foundations token ([6c44cfd](https://github.com/RocketsAreNostalgic/ran-booster/commit/6c44cfdc1f1dfbbdf1a06b9ed23b1886093df679)), closes [#611](https://github.com/RocketsAreNostalgic/ran-booster/issues/611)
* **settings:** remove the autosave sentence and hold Save setting until the script takes over ([4316b43](https://github.com/RocketsAreNostalgic/ran-booster/commit/4316b43df71f461e97cce8a9df2990ace0b6c60c)), closes [#611](https://github.com/RocketsAreNostalgic/ran-booster/issues/611)
* **settings:** retire navigation before authoritative refresh ([dbebc44](https://github.com/RocketsAreNostalgic/ran-booster/commit/dbebc44bc74624350d47600f46f53df6db42ad1a))
* **settings:** stop false save-unconfirmed warning after Back ([451df4f](https://github.com/RocketsAreNostalgic/ran-booster/commit/451df4f72aac53831447ff96c5bdd794f439e42d)), closes [#777](https://github.com/RocketsAreNostalgic/ran-booster/issues/777) [#611](https://github.com/RocketsAreNostalgic/ran-booster/issues/611)
* **settings:** stop the empty Badge mount squeezing the fallback badge ([215001f](https://github.com/RocketsAreNostalgic/ran-booster/commit/215001f45d0f1a394d81f879aaa9430767d04855)), closes [#611](https://github.com/RocketsAreNostalgic/ran-booster/issues/611)
* **transporter:** correct heading order and duplicate landmark names ([4a8ee11](https://github.com/RocketsAreNostalgic/ran-booster/commit/4a8ee11cb13b6ab213b8be7833c6292735741879))
* **transporter:** drop the doubled divider above the Create flow's first section ([8e7fc67](https://github.com/RocketsAreNostalgic/ran-booster/commit/8e7fc6755e5c20b83e9583a7c74bda3815cd322a)), closes [#754](https://github.com/RocketsAreNostalgic/ran-booster/issues/754)
* **transporter:** give table counts one plural context ([78f213d](https://github.com/RocketsAreNostalgic/ran-booster/commit/78f213d6c41f9d9502b711341cb8171bef303b12))
* **transporter:** name the export select-all by its visible label ([ae0cad6](https://github.com/RocketsAreNostalgic/ran-booster/commit/ae0cad67cdf2b206c9d88d3681e00a619b410479))
* **transporter:** share one translator note for selected counts ([13386bd](https://github.com/RocketsAreNostalgic/ran-booster/commit/13386bdba4d32c8d896b5b4f222396cb469a9dc2))
* **troubleshooting:** show the GitHub provider package version ([1085b8f](https://github.com/RocketsAreNostalgic/ran-booster/commit/1085b8fc8cd70131b5fae8d81d855899b5bc0e72)), closes [#773](https://github.com/RocketsAreNostalgic/ran-booster/issues/773)
* **ui:** call the managed repositories box a filter ([9402dca](https://github.com/RocketsAreNostalgic/ran-booster/commit/9402dca0a6cb6f151756b8fe1bb77dac1ca342fa)), closes [#742](https://github.com/RocketsAreNostalgic/ran-booster/issues/742)
* **ui:** centre the readiness title and status badge ([b538969](https://github.com/RocketsAreNostalgic/ran-booster/commit/b538969b9b78845f4dbd2a740d99ea1905cceccb))
* **ui:** clarify GitHub provider onboarding and repair footer flow ([26ec3c4](https://github.com/RocketsAreNostalgic/ran-booster/commit/26ec3c4c7488b614627478c9b652ee346c10cd66)), closes [#775](https://github.com/RocketsAreNostalgic/ran-booster/issues/775)
* **ui:** clarify secure storage setup copy and badge ([e210f10](https://github.com/RocketsAreNostalgic/ran-booster/commit/e210f10d848576a73e680c8facd5f58e34b62b15)), closes [#618](https://github.com/RocketsAreNostalgic/ran-booster/issues/618)
* **ui:** compact Release readiness guidance spacing ([cc1b314](https://github.com/RocketsAreNostalgic/ran-booster/commit/cc1b314c1de0dc19e8885d5e8a6f0a000e95643a))
* **ui:** equalise webhook step heights and space the notice above them ([2a983b6](https://github.com/RocketsAreNostalgic/ran-booster/commit/2a983b643c01fa536e66237b5ac8fa4b3fbf5830)), closes [#748](https://github.com/RocketsAreNostalgic/ran-booster/issues/748)
* **ui:** explain a rejected repository change on package edit ([fce24d8](https://github.com/RocketsAreNostalgic/ran-booster/commit/fce24d86e50c04f6203283ce61b8d5603d69c732))
* **ui:** find picker controls associated by the form attribute ([e7cd21f](https://github.com/RocketsAreNostalgic/ran-booster/commit/e7cd21f7459e291e95f3c668b7d6634c7b172181))
* **ui:** frame collection filters with their table or list ([7166d73](https://github.com/RocketsAreNostalgic/ran-booster/commit/7166d7367c7de8c30142bc7acb3d79d8d07c5857))
* **ui:** give disabled brand buttons a visible white treatment ([ec5dbdf](https://github.com/RocketsAreNostalgic/ran-booster/commit/ec5dbdf75ff478ae36272e27809b8380d10c0867))
* **ui:** give notice disclosures a full-width row with clear spacing ([af525e8](https://github.com/RocketsAreNostalgic/ran-booster/commit/af525e81e433428f996acc4c26f5d085399ebcf1)), closes [#774](https://github.com/RocketsAreNostalgic/ran-booster/issues/774)
* **ui:** keep Activity filters on one row with a truncating subject ([5c2173d](https://github.com/RocketsAreNostalgic/ran-booster/commit/5c2173df44fbaaf13e84966f985354d148c45c4b))
* **ui:** keep button-styled links at button height and group readiness links ([a93192f](https://github.com/RocketsAreNostalgic/ran-booster/commit/a93192f3a6979798bddac1567d0983a3f431e5d6))
* **ui:** keep the logging capture confirmation visually hidden ([bcbaebd](https://github.com/RocketsAreNostalgic/ran-booster/commit/bcbaebdb90388039dd5ff14a1ecc579283d31412))
* **ui:** keep the provider table a table down to 783px and compact below ([70ca309](https://github.com/RocketsAreNostalgic/ran-booster/commit/70ca3094a24221426911a77f5c70886c1779223b)), closes [#633](https://github.com/RocketsAreNostalgic/ran-booster/issues/633)
* **ui:** keep the source tab strip gap equal above the releases pane ([58a690a](https://github.com/RocketsAreNostalgic/ran-booster/commit/58a690af6ae27c9e04b569824966c8b54c169029))
* **ui:** leave live regions announceable while a dialog is modal ([44e3555](https://github.com/RocketsAreNostalgic/ran-booster/commit/44e35558cf476bd829e0fbb95288f3cb58a28e99))
* **ui:** let the Activity filter form shrink inside the toolbar ([1779a54](https://github.com/RocketsAreNostalgic/ran-booster/commit/1779a549cea619e8849cdcf482cb6eeaff1753a5))
* **ui:** lock the git provider and repository on package edit ([4c2f303](https://github.com/RocketsAreNostalgic/ran-booster/commit/4c2f303ce0095d597b7f2b1573e438bb6e631ae3))
* **ui:** make the shared dialog modal and wrap Tab focus inside it ([3596e9a](https://github.com/RocketsAreNostalgic/ran-booster/commit/3596e9a341869b4091f03a949aa6ebb2cba72f04))
* **ui:** mark plain external text links with the shared arrow ([5d317af](https://github.com/RocketsAreNostalgic/ran-booster/commit/5d317af817f02d63c9df995714ceb925dc07198b)), closes [#735](https://github.com/RocketsAreNostalgic/ran-booster/issues/735)
* **ui:** match disclosure fallback box-sizing to the mounted root ([c2aa7b2](https://github.com/RocketsAreNostalgic/ran-booster/commit/c2aa7b2e3b97c2a06f42eb1945436da9b641978b))
* **ui:** reflow readiness steps by usable width and pack heading spacing ([b4e5a2f](https://github.com/RocketsAreNostalgic/ran-booster/commit/b4e5a2fc7d0bebfdd32db9ef93e89d3ea4d606eb)), closes [#748](https://github.com/RocketsAreNostalgic/ran-booster/issues/748)
* **ui:** render source repository guidance as the shared notice ([2ba4cdc](https://github.com/RocketsAreNostalgic/ran-booster/commit/2ba4cdc3724ca570eebd6990abf79a4b00c76835))
* **ui:** restore bottom spacing below activity row disclosures ([f51523e](https://github.com/RocketsAreNostalgic/ran-booster/commit/f51523edd756e4e0bfa37e196fbc754b4ff90154)), closes [#765](https://github.com/RocketsAreNostalgic/ran-booster/issues/765)
* **ui:** restore the bundled origin on the troubleshooting provider card ([b7acc7a](https://github.com/RocketsAreNostalgic/ran-booster/commit/b7acc7ab801b200ba58099c8b763da5a3e501907))
* **ui:** scope package-row disclosure header to the 13px management size ([8ae3e18](https://github.com/RocketsAreNostalgic/ran-booster/commit/8ae3e1896ce5f35ca63ee865f67fc0303b7a57b9)), closes [#759](https://github.com/RocketsAreNostalgic/ran-booster/issues/759)
* **ui:** separate installation versions with a dot, not a code box ([edbbb57](https://github.com/RocketsAreNostalgic/ran-booster/commit/edbbb570315156c4094914926476a3b4e78605a3)), closes [#767](https://github.com/RocketsAreNostalgic/ran-booster/issues/767)
* **ui:** separate the support report action from its disclosure ([8ea06bb](https://github.com/RocketsAreNostalgic/ran-booster/commit/8ea06bb2c2d997f2ce47e2615fa7a83d67e94e44)), closes [#746](https://github.com/RocketsAreNostalgic/ran-booster/issues/746)
* **ui:** shorten the credential type column heading to Type ([3dacf40](https://github.com/RocketsAreNostalgic/ran-booster/commit/3dacf40dcb1a7f45452921cdcc056d544665c596))
* **ui:** show credential types by their short labels ([88efc6d](https://github.com/RocketsAreNostalgic/ran-booster/commit/88efc6d42b5b33e1246483a4bb4d2852c05045f0))
* **ui:** show credential validation results in a full-width row ([ef3c627](https://github.com/RocketsAreNostalgic/ran-booster/commit/ef3c6274b425b39bf4dde349e5c140c5bbae28a2)), closes [#763](https://github.com/RocketsAreNostalgic/ran-booster/issues/763)
* **ui:** show external destinations as links with an arrow ([9b48271](https://github.com/RocketsAreNostalgic/ran-booster/commit/9b482712bad41ae956bb3dd4400c1f43dc33cce1)), closes [#735](https://github.com/RocketsAreNostalgic/ran-booster/issues/735)
* **ui:** show provider trust guidance as a full-width notice ([d33c515](https://github.com/RocketsAreNostalgic/ran-booster/commit/d33c51502ce6b123236e376fd98be44da5fdf9fd)), closes [#745](https://github.com/RocketsAreNostalgic/ran-booster/issues/745)
* **ui:** show switch explanations as static notices ([54f8005](https://github.com/RocketsAreNostalgic/ran-booster/commit/54f80055dc5303769db294e6ca6550b3e53b795e)), closes [#756](https://github.com/RocketsAreNostalgic/ran-booster/issues/756)
* **ui:** show the managed search purpose inside the field ([23ef4cb](https://github.com/RocketsAreNostalgic/ran-booster/commit/23ef4cb8dc87c1ad81f55b238b8ba73d50a2f215)), closes [#681](https://github.com/RocketsAreNostalgic/ran-booster/issues/681)
* **ui:** show the publishing line as a strapline under the card heading ([94fb06a](https://github.com/RocketsAreNostalgic/ran-booster/commit/94fb06ab15c6d28dbe098a6025eec5dc934e8636))
* **ui:** space the blueprint requirements disclosure above the table ([ded7d81](https://github.com/RocketsAreNostalgic/ran-booster/commit/ded7d81b0d6a4eed1ed75411543aac56678e223f))
* **ui:** space the blueprint review notice below the import inputs ([eccc60c](https://github.com/RocketsAreNostalgic/ran-booster/commit/eccc60c8c9a44b1616a9036ced7ec81cc6481e8e))
* **ui:** space the release workflow badge from its disclosure title ([f5574d2](https://github.com/RocketsAreNostalgic/ran-booster/commit/f5574d2333ce1b2542f84fea47927bc0e69d907d))
* **ui:** use sentence case for the release track label ([5e5c77b](https://github.com/RocketsAreNostalgic/ran-booster/commit/5e5c77b57662b52339cbafaa46775cc3e17af5a0))
* **ui:** use the shared ready and not ready words for release readiness ([bb59347](https://github.com/RocketsAreNostalgic/ran-booster/commit/bb593473567b001d309ff62e8230b87e2156ef0e))
* **webhook:** present a clean validate check as a success ([a0ed66f](https://github.com/RocketsAreNostalgic/ran-booster/commit/a0ed66ff3d9fa7626568320ecdca52e785873803))
* **webhooks:** declare manual_notice in the panel shape ([bd57819](https://github.com/RocketsAreNostalgic/ran-booster/commit/bd57819653d2619c03008041431c3aee5492bbf5))
* **webhooks:** declare manual_notice on the unavailable panel ([ace08a1](https://github.com/RocketsAreNostalgic/ran-booster/commit/ace08a16e734d90d8d5f36e04e4129c9928218ed))
* **webhooks:** ping after every successful Validate check ([f8e0314](https://github.com/RocketsAreNostalgic/ran-booster/commit/f8e0314d46c0c3297eed969620780bebd00182b8))
* **webhooks:** present unrecorded deliveries as evidence only ([298bf32](https://github.com/RocketsAreNostalgic/ran-booster/commit/298bf3282907cfacd4175bac72b029063b666c7d))
* **webhooks:** remove the Manage repositories link from the manual setup disclosure ([fd94429](https://github.com/RocketsAreNostalgic/ran-booster/commit/fd94429ebd14edaefc8c3ff7b4ad785e6191aa25)), closes [#741](https://github.com/RocketsAreNostalgic/ran-booster/issues/741)
* **webhook:** state the true disabled reason on each webhook button ([52ecf99](https://github.com/RocketsAreNostalgic/ran-booster/commit/52ecf991fe23821098da8738566d49549cb49c4d))


### Reverts

* **overview:** group Managed packages card by package type ([169fd7e](https://github.com/RocketsAreNostalgic/ran-booster/commit/169fd7e8ec5be6b863e918fbe0f953662331a8ef))
* **transporter:** Transporter flattening passes for [#754](https://github.com/RocketsAreNostalgic/ran-booster/issues/754) ([efbfb67](https://github.com/RocketsAreNostalgic/ran-booster/commit/efbfb672984e93b24f3dd0e5bbca7bb3b1490782))

## [1.0.0-beta.43](https://github.com/RocketsAreNostalgic/ran-booster/compare/v1.0.0-beta.42...v1.0.0-beta.43) (2026-11-04)


### Bug Fixes

* remove settled selection during WordPress uninstall ([#639](https://github.com/RocketsAreNostalgic/ran-booster/issues/639)) ([ff9e2f6](https://github.com/RocketsAreNostalgic/ran-booster/commit/ff9e2f6aecf255c9979e2c4179c45b9f23a634c2))
* verify uninstall option absence from database ([#648](https://github.com/RocketsAreNostalgic/ran-booster/issues/648)) ([ff3810c](https://github.com/RocketsAreNostalgic/ran-booster/commit/ff3810c7ea6366c94f96a8334184fdcbd90afe4b))

## [1.0.0-beta.42](https://github.com/RocketsAreNostalgic/ran-booster/compare/v1.0.0-beta.41...v1.0.0-beta.42) (2026-11-02)


### ⚠ BREAKING CHANGES

* **secrets:** use ran-booster-store.json for clean prerelease installs ([#608](https://github.com/RocketsAreNostalgic/ran-booster/issues/608))
* rename archive limits to download limits ([#606](https://github.com/RocketsAreNostalgic/ran-booster/issues/606))

### Features

* add one-level documentation chapters ([#586](https://github.com/RocketsAreNostalgic/ran-booster/issues/586)) ([842175c](https://github.com/RocketsAreNostalgic/ran-booster/commit/842175c060b8e2da23be130dcfb3c8d740a63f7a))
* **admin:** add responsive Overview card layouts ([#615](https://github.com/RocketsAreNostalgic/ran-booster/issues/615)) ([04283ee](https://github.com/RocketsAreNostalgic/ran-booster/commit/04283ee1147ac22204fa7228d8dfb22456a85f63))
* **admin:** refine Overview, Settings and Troubleshooting ([#584](https://github.com/RocketsAreNostalgic/ran-booster/issues/584)) ([32214bc](https://github.com/RocketsAreNostalgic/ran-booster/commit/32214bc68ca552f40d7d70facd3f101f2d7a87e6))
* **extensions:** complete activation and management journey ([#566](https://github.com/RocketsAreNostalgic/ran-booster/issues/566)) ([69ec1ac](https://github.com/RocketsAreNostalgic/ran-booster/commit/69ec1aca3b511b21727f3e8c031cfbc96736a7de))
* rename archive limits to download limits ([#606](https://github.com/RocketsAreNostalgic/ran-booster/issues/606)) ([0105deb](https://github.com/RocketsAreNostalgic/ran-booster/commit/0105debeaddee300785d2d488021c7b79e445bc8))


### Bug Fixes

* **admin:** adopt pre-shell native notice placement ([#562](https://github.com/RocketsAreNostalgic/ran-booster/issues/562)) ([8ede3fc](https://github.com/RocketsAreNostalgic/ran-booster/commit/8ede3fc1d9936a1a37471c003a06dc43fedc31f4))
* **admin:** associate expiry actions with each credential ([#597](https://github.com/RocketsAreNostalgic/ran-booster/issues/597)) ([357de46](https://github.com/RocketsAreNostalgic/ran-booster/commit/357de466368e03a3670fc896d648d8a90d6495a2))
* **admin:** clarify Git provider terminology ([#570](https://github.com/RocketsAreNostalgic/ran-booster/issues/570)) ([0dea2b6](https://github.com/RocketsAreNostalgic/ran-booster/commit/0dea2b658a12d3211ef3302f1dd04d039e65bfde))
* **admin:** clarify installation recovery notice ([#591](https://github.com/RocketsAreNostalgic/ran-booster/issues/591)) ([7f7a5d1](https://github.com/RocketsAreNostalgic/ran-booster/commit/7f7a5d15b39853ab79a52034c88149ce33b98d05))
* **admin:** clarify saved credential storage failures ([#598](https://github.com/RocketsAreNostalgic/ran-booster/issues/598)) ([afbff0b](https://github.com/RocketsAreNostalgic/ran-booster/commit/afbff0b016932646e671c23cf39dd4a89717979f))
* **admin:** clarify uncertain installation results ([#599](https://github.com/RocketsAreNostalgic/ran-booster/issues/599)) ([71d5b93](https://github.com/RocketsAreNostalgic/ran-booster/commit/71d5b93d24bba5d574c86136bdc0b09e0fae451e))
* **admin:** clarify webhook result and recovery notices ([#602](https://github.com/RocketsAreNostalgic/ran-booster/issues/602)) ([f22eb5f](https://github.com/RocketsAreNostalgic/ran-booster/commit/f22eb5f888a551438e48e09f9f8709e248c39e04))
* **admin:** describe available provider evidence honestly ([#609](https://github.com/RocketsAreNostalgic/ran-booster/issues/609)) ([2d5f51c](https://github.com/RocketsAreNostalgic/ran-booster/commit/2d5f51cbf6f7466587318bc63ad65bb77c9d63fe))
* **admin:** remove remaining eyebrow markup and styles ([#563](https://github.com/RocketsAreNostalgic/ran-booster/issues/563)) ([599a7cc](https://github.com/RocketsAreNostalgic/ran-booster/commit/599a7cc9218d0064095f11ef26cce8c200e81c04))
* **admin:** separate provider status and approval ([#601](https://github.com/RocketsAreNostalgic/ran-booster/issues/601)) ([f84b5fb](https://github.com/RocketsAreNostalgic/ran-booster/commit/f84b5fbcc6741cf60aa6ce7fdadb47b2b72b9996))
* **admin:** start new installations with onboarding ([#610](https://github.com/RocketsAreNostalgic/ran-booster/issues/610)) ([8189485](https://github.com/RocketsAreNostalgic/ran-booster/commit/8189485c544b9417e87140a3061db097cbe10084))
* contain final Activity row spacing ([#605](https://github.com/RocketsAreNostalgic/ran-booster/issues/605)) ([7de6f87](https://github.com/RocketsAreNostalgic/ran-booster/commit/7de6f874c1443cd31e189034463413cf22f9517d))
* **deps:** pin Admin Shell v1.0.0 ([#600](https://github.com/RocketsAreNostalgic/ran-booster/issues/600)) ([635ae98](https://github.com/RocketsAreNostalgic/ran-booster/commit/635ae980e93084ff126ee281ca302e88a9a00e10))
* **secrets:** use ran-booster-store.json for clean prerelease installs ([#608](https://github.com/RocketsAreNostalgic/ran-booster/issues/608)) ([1bc1a8e](https://github.com/RocketsAreNostalgic/ran-booster/commit/1bc1a8efa0dc712fdcef3baab56bbad361d3f4d9))

## [1.0.0-beta.41](https://github.com/RocketsAreNostalgic/ran-booster/compare/v1.0.0-beta.40...v1.0.0-beta.41) (2026-10-30)


### Features

* **admin:** offer Transporter export before Booster deletion ([#547](https://github.com/RocketsAreNostalgic/ran-booster/issues/547)) ([dcbcdd3](https://github.com/RocketsAreNostalgic/ran-booster/commit/dcbcdd3e99df5a99a9c67568cdb25ec2b52f38cd))
* integrate Beta41 safety and extension updates ([#556](https://github.com/RocketsAreNostalgic/ran-booster/issues/556)) ([dcfe7b7](https://github.com/RocketsAreNostalgic/ran-booster/commit/dcfe7b7a20f4d2dee0b730d7f5d8f218363f1b4e))


### Bug Fixes

* **admin:** prevent hidden notices flashing shell spacing ([#559](https://github.com/RocketsAreNostalgic/ran-booster/issues/559)) ([532595c](https://github.com/RocketsAreNostalgic/ran-booster/commit/532595cc7f5cb81b9a225e3dbe847b1eec56940b))

## [1.0.0-beta.40](https://github.com/RocketsAreNostalgic/ran-booster/compare/v1.0.0-beta.39...v1.0.0-beta.40) (2026-10-29)


### Features

* **admin:** display installed Booster version in footer ([#545](https://github.com/RocketsAreNostalgic/ran-booster/issues/545)) ([8de338c](https://github.com/RocketsAreNostalgic/ran-booster/commit/8de338c3775f5b0271844084f982839ece4810fb))
* **extensions:** install selected releases from the catalogue ([#530](https://github.com/RocketsAreNostalgic/ran-booster/issues/530)) ([3b0a210](https://github.com/RocketsAreNostalgic/ran-booster/commit/3b0a210934706b5c6cacc1f47ad22714da7f87c2))


### Bug Fixes

* **activity:** separate status from result messages ([#524](https://github.com/RocketsAreNostalgic/ran-booster/issues/524)) ([20ba4b3](https://github.com/RocketsAreNostalgic/ran-booster/commit/20ba4b387c3b2818d9bf92de9a5d967e88ff49c6))
* **admin:** align local navigation and reserve loading feedback ([#546](https://github.com/RocketsAreNostalgic/ran-booster/issues/546)) ([5c856b6](https://github.com/RocketsAreNostalgic/ran-booster/commit/5c856b6734395f114bf831eece0c137e4cc9d7ce))
* **admin:** align mobile package selection rows ([#539](https://github.com/RocketsAreNostalgic/ran-booster/issues/539)) ([093393a](https://github.com/RocketsAreNostalgic/ran-booster/commit/093393ad617744e513324ddef4079b6e0af80776))
* **admin:** align page headings and framing ([#526](https://github.com/RocketsAreNostalgic/ran-booster/issues/526)) ([ee21ced](https://github.com/RocketsAreNostalgic/ran-booster/commit/ee21cedb78a77bc2059f929a1812408c07f43e12))
* **admin:** align provider diagnostics disclosure ([#536](https://github.com/RocketsAreNostalgic/ran-booster/issues/536)) ([6820ae5](https://github.com/RocketsAreNostalgic/ran-booster/commit/6820ae5a603385ffd738170848a5501ef6e4ecd5))
* **admin:** contain Booster notices and add reminder preferences ([#552](https://github.com/RocketsAreNostalgic/ran-booster/issues/552)) ([a47112a](https://github.com/RocketsAreNostalgic/ran-booster/commit/a47112adbdf9b75141156b33a0fd0fe31b46e01c))
* **admin:** contain repository status sidebar badges ([#554](https://github.com/RocketsAreNostalgic/ran-booster/issues/554)) ([794fc61](https://github.com/RocketsAreNostalgic/ran-booster/commit/794fc617b9e8f508b9fe89e935c0ae6d47be906d))
* **admin:** disable inactive release track settings ([#549](https://github.com/RocketsAreNostalgic/ran-booster/issues/549)) ([1a5a29d](https://github.com/RocketsAreNostalgic/ran-booster/commit/1a5a29d6d0eaf21ea48224df0b8339dac3afd758))
* **admin:** make Blueprint review easier to scan ([#527](https://github.com/RocketsAreNostalgic/ran-booster/issues/527)) ([d4ddb4e](https://github.com/RocketsAreNostalgic/ran-booster/commit/d4ddb4ee6ecb3d8f59f2f084991e1cd07b9bab72))
* **admin:** preserve narrow package checkbox gutters ([#553](https://github.com/RocketsAreNostalgic/ran-booster/issues/553)) ([6da0ef9](https://github.com/RocketsAreNostalgic/ran-booster/commit/6da0ef931b64c1352bce11507a8d96a3feeb4984))
* **admin:** preserve source-route focus and contain package controls ([#522](https://github.com/RocketsAreNostalgic/ran-booster/issues/522)) ([d9caef1](https://github.com/RocketsAreNostalgic/ran-booster/commit/d9caef14feb2ad5a594b7104226886d7729e7b2e))
* **admin:** remove credential and webhook page eyebrows ([#548](https://github.com/RocketsAreNostalgic/ran-booster/issues/548)) ([259875f](https://github.com/RocketsAreNostalgic/ran-booster/commit/259875f41daf0b1fbc5113fd25bc99633b3efd7b))
* **admin:** space webhook setup from branch controls ([#551](https://github.com/RocketsAreNostalgic/ran-booster/issues/551)) ([19ba8f3](https://github.com/RocketsAreNostalgic/ran-booster/commit/19ba8f3f2f61329f8e0655f41f9a52ac73912ceb))
* **admin:** span package safety notice across card ([#533](https://github.com/RocketsAreNostalgic/ran-booster/issues/533)) ([e87530c](https://github.com/RocketsAreNostalgic/ran-booster/commit/e87530c7f55b40d5a909a68c12f9ca4dfba1fb9d))
* **admin:** tint repository lookup notice ([#532](https://github.com/RocketsAreNostalgic/ran-booster/issues/532)) ([3bcec32](https://github.com/RocketsAreNostalgic/ran-booster/commit/3bcec32154f3dcfa36857458f8c13d4da3b9a1bd))
* **credentials:** keep validation feedback with health ([#525](https://github.com/RocketsAreNostalgic/ran-booster/issues/525)) ([31ef134](https://github.com/RocketsAreNostalgic/ran-booster/commit/31ef134d3f6ea337511b0f7e9adfe77de16b000f))
* **deps:** adopt release updater beta.14 ([#520](https://github.com/RocketsAreNostalgic/ran-booster/issues/520)) ([160dac5](https://github.com/RocketsAreNostalgic/ran-booster/commit/160dac5045b8bc16fcdf31821edb929d8d340769))
* **transporter:** identify Blueprint export downloads ([#523](https://github.com/RocketsAreNostalgic/ran-booster/issues/523)) ([2ca6db0](https://github.com/RocketsAreNostalgic/ran-booster/commit/2ca6db03dd00a73887bdac358dc198d151ca5ff0))

## [1.0.0-beta.39](https://github.com/RocketsAreNostalgic/ran-booster/compare/v1.0.0-beta.38...v1.0.0-beta.39) (2026-10-25)


### Bug Fixes

* **deps:** patch fast-uri within existing parent constraints ([#514](https://github.com/RocketsAreNostalgic/ran-booster/issues/514)) ([47f3b44](https://github.com/RocketsAreNostalgic/ran-booster/commit/47f3b44668da2f8d6929985fb046e1a2efd66de3))
* **removal:** preserve unfinished native update ownership ([#510](https://github.com/RocketsAreNostalgic/ran-booster/issues/510)) ([43f5706](https://github.com/RocketsAreNostalgic/ran-booster/commit/43f570699309aa83566d867237882773447e1490))
* **updater:** adopt beta.13 theme recovery screen fix ([#512](https://github.com/RocketsAreNostalgic/ran-booster/issues/512)) ([a0e9dd1](https://github.com/RocketsAreNostalgic/ran-booster/commit/a0e9dd1d2c3a2d5fa6bfcd289895bda5b6ea77a2))

## [1.0.0-beta.38](https://github.com/RocketsAreNostalgic/ran-booster/compare/v1.0.0-beta.37...v1.0.0-beta.38) (2026-10-24)


### Features

* **admin:** keep shared documentation provider-neutral ([#504](https://github.com/RocketsAreNostalgic/ran-booster/issues/504)) ([59d343a](https://github.com/RocketsAreNostalgic/ran-booster/commit/59d343aeb70d4d63ca9af51f32b99c16f096eb23))
* **provider:** expose guarded native update checkpoints ([#499](https://github.com/RocketsAreNostalgic/ran-booster/issues/499)) ([65369e7](https://github.com/RocketsAreNostalgic/ran-booster/commit/65369e79d281c11180303e5526c7c486abea6afd))
* **transporter:** adopt installed Release packages directly ([#509](https://github.com/RocketsAreNostalgic/ran-booster/issues/509)) ([6f34480](https://github.com/RocketsAreNostalgic/ran-booster/commit/6f34480e46964f489278675495c67c12b1cb7a8e))
* **transporter:** install ordinary unpinned Release packages ([#511](https://github.com/RocketsAreNostalgic/ran-booster/issues/511)) ([c558cf3](https://github.com/RocketsAreNostalgic/ran-booster/commit/c558cf334f240c4f8dfa177a25eeed3d4da37197))


### Bug Fixes

* **admin:** retain GitHub adoption feedback across redirects ([#502](https://github.com/RocketsAreNostalgic/ran-booster/issues/502)) ([2a97240](https://github.com/RocketsAreNostalgic/ran-booster/commit/2a97240d46e7d0c8821a7fdacddd738f5ddaa381))
* **extensions:** reconcile catalogue with published satellite releases ([#508](https://github.com/RocketsAreNostalgic/ran-booster/issues/508)) ([f1ec4cb](https://github.com/RocketsAreNostalgic/ran-booster/commit/f1ec4cbc9bb8bcbe983472315c047908afa4cc6e))
* recover native update queues and surface uncertain results ([#501](https://github.com/RocketsAreNostalgic/ran-booster/issues/501)) ([b026542](https://github.com/RocketsAreNostalgic/ran-booster/commit/b026542da75d88a36eb78ca6b9517ca7bb69433e))

## [1.0.0-beta.37](https://github.com/RocketsAreNostalgic/ran-booster/compare/v1.0.0-beta.36...v1.0.0-beta.37) (2026-10-21)


### Features

* integrate Blueprint V2 configuration and bounded previews ([#424](https://github.com/RocketsAreNostalgic/ran-booster/issues/424)) ([c7d9bca](https://github.com/RocketsAreNostalgic/ran-booster/commit/c7d9bcad65c8def809f15dea7dd0e7158f605f7b))
* integrate GitHub replacement and Blueprint ecosystem ([#437](https://github.com/RocketsAreNostalgic/ran-booster/issues/437)) ([6e8d3d6](https://github.com/RocketsAreNostalgic/ran-booster/commit/6e8d3d6a5198c1ea99f0faeec97ec2922bb00102))

## [1.0.0-beta.36](https://github.com/RocketsAreNostalgic/ran-booster/compare/v1.0.0-beta.35...v1.0.0-beta.36) (2026-10-15)


### Features

* **core:** integrate qualified Booster simplifications ([#393](https://github.com/RocketsAreNostalgic/ran-booster/issues/393)) ([a3ae152](https://github.com/RocketsAreNostalgic/ran-booster/commit/a3ae15265fa0ffe482786872a259e2441df571d0))

## [1.0.0-beta.35](https://github.com/RocketsAreNostalgic/ran-booster/compare/v1.0.0-beta.34...v1.0.0-beta.35) (2026-10-13)


### Features

* add durable shared activity history and package check summaries ([#307](https://github.com/RocketsAreNostalgic/ran-booster/issues/307)) ([7ee4c7a](https://github.com/RocketsAreNostalgic/ran-booster/commit/7ee4c7aaaf6292e821ecb858782d40e5109c314f))
* adoption lifetime identity and safe corrupt-management recovery ([#305](https://github.com/RocketsAreNostalgic/ran-booster/issues/305)) ([ef5a9c1](https://github.com/RocketsAreNostalgic/ran-booster/commit/ef5a9c10fc89528116d034d38f186dadf2c9b5a4))
* record webhook credential and diagnostic outcomes ([#308](https://github.com/RocketsAreNostalgic/ran-booster/issues/308)) ([24d37f8](https://github.com/RocketsAreNostalgic/ran-booster/commit/24d37f83076b975aa668360c662ea711227c46d4))
* report deployments through Activity API ([#309](https://github.com/RocketsAreNostalgic/ran-booster/issues/309)) ([9394a7d](https://github.com/RocketsAreNostalgic/ran-booster/commit/9394a7d5357a1b979a7261659eb914070d301372))


### Bug Fixes

* **storage:** validate persisted privacy and clarify row checks ([#303](https://github.com/RocketsAreNostalgic/ran-booster/issues/303)) ([890a8a8](https://github.com/RocketsAreNostalgic/ran-booster/commit/890a8a8e186da595040acd6966c6d1a6979be2cd))

## [1.0.0-beta.34](https://github.com/RocketsAreNostalgic/ran-booster/compare/v1.0.0-beta.33...v1.0.0-beta.34) (2026-10-10)


### Bug Fixes

* require bootstrapped host contracts in Core administration ([#297](https://github.com/RocketsAreNostalgic/ran-booster/issues/297)) ([1461ece](https://github.com/RocketsAreNostalgic/ran-booster/commit/1461ecef362429f20c14bbefc32a77c612671291))

## [1.0.0-beta.33](https://github.com/RocketsAreNostalgic/ran-booster/compare/v1.0.0-beta.32...v1.0.0-beta.33) (2026-10-10)


### Bug Fixes

* adopt Branch Updater beta.9 with installed consumer proof ([#270](https://github.com/RocketsAreNostalgic/ran-booster/issues/270)) ([37f2577](https://github.com/RocketsAreNostalgic/ran-booster/commit/37f2577bd2ea0a7770d7bcb93910eecde57fc4a8))
* adopt GitHub Provider beta.15 ([#290](https://github.com/RocketsAreNostalgic/ran-booster/issues/290)) ([429f156](https://github.com/RocketsAreNostalgic/ran-booster/commit/429f1566ff99dfc0a449e311df9f26ba8e7bc03a))
* complete database and nullable runtime contracts ([#293](https://github.com/RocketsAreNostalgic/ran-booster/issues/293)) ([0639906](https://github.com/RocketsAreNostalgic/ran-booster/commit/0639906e893fb617ca504342eb263544861d896f))
* enforce storage readiness and native package contracts ([#288](https://github.com/RocketsAreNostalgic/ran-booster/issues/288)) ([db6478d](https://github.com/RocketsAreNostalgic/ran-booster/commit/db6478d2c52500803fca797f3e47a61b5a6e25bc))
* establish package hydration and credential usage contracts ([#292](https://github.com/RocketsAreNostalgic/ran-booster/issues/292)) ([38cbf61](https://github.com/RocketsAreNostalgic/ran-booster/commit/38cbf615ad018e79c1e231ef14f9b235fb3ea285))
* preserve workflow failures independently of translations ([#287](https://github.com/RocketsAreNostalgic/ran-booster/issues/287)) ([e247d41](https://github.com/RocketsAreNostalgic/ran-booster/commit/e247d4106e918d48fdb10e92beacc9862c8a30b1))

## [1.0.0-beta.32](https://github.com/RocketsAreNostalgic/ran-booster/compare/v1.0.0-beta.31...v1.0.0-beta.32) (2026-10-08)


### Bug Fixes

* clarify release control type and failure contracts ([#241](https://github.com/RocketsAreNostalgic/ran-booster/issues/241)) ([4ecc314](https://github.com/RocketsAreNostalgic/ran-booster/commit/4ecc3146a595202a9d0162acb2410956a4c7ea2d))
* **deps:** adopt immutable GitHub Provider beta.14 ([#264](https://github.com/RocketsAreNostalgic/ran-booster/issues/264)) ([143ee52](https://github.com/RocketsAreNostalgic/ran-booster/commit/143ee5246c917262175e42bc43d255fe5ba6f0dc))
* describe secrets filesystem observations and type contracts ([#239](https://github.com/RocketsAreNostalgic/ran-booster/issues/239)) ([11bfdf9](https://github.com/RocketsAreNostalgic/ran-booster/commit/11bfdf963d597357eeaf80fbf5b7edf9029755cb))
* enforce package storage and blueprint contracts ([#244](https://github.com/RocketsAreNostalgic/ran-booster/issues/244)) ([1e37068](https://github.com/RocketsAreNostalgic/ran-booster/commit/1e370689a4612a20ab5515a3ea324fe06f0cc92a))
* **quality:** clarify bootstrap and container boundaries ([#247](https://github.com/RocketsAreNostalgic/ran-booster/issues/247)) ([a4dee29](https://github.com/RocketsAreNostalgic/ran-booster/commit/a4dee2950eb7bcafb87481c8650baac54bd7e9b3))
* **release:** restore metadata after abandoned beta.32 proposal ([#265](https://github.com/RocketsAreNostalgic/ran-booster/issues/265)) ([6c184d7](https://github.com/RocketsAreNostalgic/ran-booster/commit/6c184d75058a6fb6baaeef639e91bb2b292f848f))
* retain shipped annotations required by independent Plugin Check ([4181753](https://github.com/RocketsAreNostalgic/ran-booster/commit/4181753b3fcc4371b2a3eb6e07d82ce12fe1f95c))

## [1.0.0-beta.31](https://github.com/RocketsAreNostalgic/ran-booster/compare/v1.0.0-beta.30...v1.0.0-beta.31) (2026-10-03)


### ⚠ BREAKING CHANGES

* remove legacy debug capture metadata reader ([#233](https://github.com/RocketsAreNostalgic/ran-booster/issues/233))
* remove obsolete artifact-limit and Dispatcher arguments ([#229](https://github.com/RocketsAreNostalgic/ran-booster/issues/229))
* complete remaining owned PHP naming for beta.31 ([#224](https://github.com/RocketsAreNostalgic/ran-booster/issues/224))
* integrate Portability API3 naming with preserved wire contracts ([#222](https://github.com/RocketsAreNostalgic/ran-booster/issues/222))
* adopt Provider API13 contracts and published GitHub Provider beta.10 ([#220](https://github.com/RocketsAreNostalgic/ran-booster/issues/220))
* adopt connected Branch and Provider naming releases ([#218](https://github.com/RocketsAreNostalgic/ran-booster/issues/218))
* The initial workflow cut introduced Provider API 12 and removed the V2 workflow interface and later template-update operations. The completed beta.31 composition requires Provider API 14 and bundles immutable GitHub Provider 1.0.0-beta.13. Provider admission uses exact API-marker guards. Release workflow API 3 supports initial setup only; historical occupied records retain ownership without restoring retired update authority.
* migrate mutation guard and self-update policy naming ([#192](https://github.com/RocketsAreNostalgic/ran-booster/issues/192))
* migrate package command and result naming ([#191](https://github.com/RocketsAreNostalgic/ran-booster/issues/191))
* migrate storage mutation and failure naming ([#190](https://github.com/RocketsAreNostalgic/ran-booster/issues/190))
* migrate deployment attempt and worker naming ([#189](https://github.com/RocketsAreNostalgic/ran-booster/issues/189))
* migrate package subdirectory naming ([#188](https://github.com/RocketsAreNostalgic/ran-booster/issues/188))
* migrate deployment storage failure naming ([#187](https://github.com/RocketsAreNostalgic/ran-booster/issues/187))
* migrate deployment check failure naming ([#186](https://github.com/RocketsAreNostalgic/ran-booster/issues/186))
* migrate deployment request naming ([#185](https://github.com/RocketsAreNostalgic/ran-booster/issues/185))
* migrate deployment outcome naming ([#184](https://github.com/RocketsAreNostalgic/ran-booster/issues/184))
* migrate package source factory naming ([#183](https://github.com/RocketsAreNostalgic/ran-booster/issues/183))
* migrate deployment policy and state naming ([#182](https://github.com/RocketsAreNostalgic/ran-booster/issues/182))

### Features

* adopt Provider API13 contracts and published GitHub Provider beta.10 ([#220](https://github.com/RocketsAreNostalgic/ran-booster/issues/220)) ([d5b35ac](https://github.com/RocketsAreNostalgic/ran-booster/commit/d5b35ac53692fc3f40c8cad76eef35155a360ec8))
* adopt released protocol5 updater and GitHub Provider beta.11 ([#223](https://github.com/RocketsAreNostalgic/ran-booster/issues/223)) ([3cc0c44](https://github.com/RocketsAreNostalgic/ran-booster/commit/3cc0c44bb19cd8e1fe32e299067900ca3a415e07))
* cut repository providers over to initial-only workflow API 12 ([c335d6a](https://github.com/RocketsAreNostalgic/ran-booster/commit/c335d6a1322db6dbb51dec4dee6c0fe2d026cc1e))


### Bug Fixes

* adopt released journal and Support dependencies ([#180](https://github.com/RocketsAreNostalgic/ran-booster/issues/180)) ([a992e9d](https://github.com/RocketsAreNostalgic/ran-booster/commit/a992e9d58b555ee019e6bd69fb61eec12c8d19e9))
* enforce secrets and webhook boundary contracts ([#231](https://github.com/RocketsAreNostalgic/ran-booster/issues/231)) ([6aad7d0](https://github.com/RocketsAreNostalgic/ran-booster/commit/6aad7d0f303930b2b6de41618c6c99e7b80bf0f1))
* preserve repository webhook panel output ([#228](https://github.com/RocketsAreNostalgic/ran-booster/issues/228)) ([775087a](https://github.com/RocketsAreNostalgic/ran-booster/commit/775087ad93e88a2901d992ff525fb5345d45ff2c))


### Code Refactoring

* adopt connected Branch and Provider naming releases ([#218](https://github.com/RocketsAreNostalgic/ran-booster/issues/218)) ([9b634bd](https://github.com/RocketsAreNostalgic/ran-booster/commit/9b634bdf10ae2866ceca54ac6262d4845fa859d2))
* complete remaining owned PHP naming for beta.31 ([#224](https://github.com/RocketsAreNostalgic/ran-booster/issues/224)) ([e0f7046](https://github.com/RocketsAreNostalgic/ran-booster/commit/e0f7046521ad3c3b8ab265721efa27b53360d806))
* integrate Portability API3 naming with preserved wire contracts ([#222](https://github.com/RocketsAreNostalgic/ran-booster/issues/222)) ([290fdd1](https://github.com/RocketsAreNostalgic/ran-booster/commit/290fdd164a3f483b84e61b91daba123457aac672))
* migrate deployment attempt and worker naming ([#189](https://github.com/RocketsAreNostalgic/ran-booster/issues/189)) ([14de5b6](https://github.com/RocketsAreNostalgic/ran-booster/commit/14de5b69f48bebba6509a68062e84a224fde66ac))
* migrate deployment check failure naming ([#186](https://github.com/RocketsAreNostalgic/ran-booster/issues/186)) ([97f090f](https://github.com/RocketsAreNostalgic/ran-booster/commit/97f090f979ce308370fa94574a88a6a24949102a))
* migrate deployment outcome naming ([#184](https://github.com/RocketsAreNostalgic/ran-booster/issues/184)) ([d043be0](https://github.com/RocketsAreNostalgic/ran-booster/commit/d043be06a06c2102eda064bdc7a75a1a7466622c))
* migrate deployment policy and state naming ([#182](https://github.com/RocketsAreNostalgic/ran-booster/issues/182)) ([110859e](https://github.com/RocketsAreNostalgic/ran-booster/commit/110859e09199d558a5f60a1d1cc9c42779172590))
* migrate deployment request naming ([#185](https://github.com/RocketsAreNostalgic/ran-booster/issues/185)) ([e6a5710](https://github.com/RocketsAreNostalgic/ran-booster/commit/e6a5710417886ba9d18a1d4273fe1ab001403542))
* migrate deployment storage failure naming ([#187](https://github.com/RocketsAreNostalgic/ran-booster/issues/187)) ([2753e34](https://github.com/RocketsAreNostalgic/ran-booster/commit/2753e34b0b50a53f69e21e6a0db43c55fc903917))
* migrate mutation guard and self-update policy naming ([#192](https://github.com/RocketsAreNostalgic/ran-booster/issues/192)) ([1e76119](https://github.com/RocketsAreNostalgic/ran-booster/commit/1e7611901a2966524886b8e6c14efc4f87a71101))
* migrate package command and result naming ([#191](https://github.com/RocketsAreNostalgic/ran-booster/issues/191)) ([a34c4e0](https://github.com/RocketsAreNostalgic/ran-booster/commit/a34c4e082f17fe9ad77f64aa6f63f3eff7c91bb6))
* migrate package source factory naming ([#183](https://github.com/RocketsAreNostalgic/ran-booster/issues/183)) ([5ab1334](https://github.com/RocketsAreNostalgic/ran-booster/commit/5ab1334d809ea5bef356e470b8af9c3f6206a3b5))
* migrate package subdirectory naming ([#188](https://github.com/RocketsAreNostalgic/ran-booster/issues/188)) ([ca45654](https://github.com/RocketsAreNostalgic/ran-booster/commit/ca45654a27827e2afba79d2cfcf6c07652285d8d))
* migrate storage mutation and failure naming ([#190](https://github.com/RocketsAreNostalgic/ran-booster/issues/190)) ([c731a25](https://github.com/RocketsAreNostalgic/ran-booster/commit/c731a25e1e66949b57bd2b281f63a1322e93c015))
* remove legacy debug capture metadata reader ([#233](https://github.com/RocketsAreNostalgic/ran-booster/issues/233)) ([e7b26f8](https://github.com/RocketsAreNostalgic/ran-booster/commit/e7b26f8e836ca8c73602cd2a3547f11a9d7156ce))
* remove obsolete artifact-limit and Dispatcher arguments ([#229](https://github.com/RocketsAreNostalgic/ran-booster/issues/229)) ([fdf6ce9](https://github.com/RocketsAreNostalgic/ran-booster/commit/fdf6ce9e74cf7a3f514111b83ece3a754156bd26))

## [1.0.0-beta.30](https://github.com/RocketsAreNostalgic/ran-booster/compare/v1.0.0-beta.29...v1.0.0-beta.30) (2026-09-24)


### Bug Fixes

* **release:** adopt shared Profile B lifecycle ([#170](https://github.com/RocketsAreNostalgic/ran-booster/issues/170)) ([68c30b5](https://github.com/RocketsAreNostalgic/ran-booster/commit/68c30b5216f89dc87e40e1d768cab730ef3fbc58))

## [1.0.0-beta.29](https://github.com/RocketsAreNostalgic/ran-booster/compare/v1.0.0-beta.28...v1.0.0-beta.29) (2026-09-20)


### ⚠ BREAKING CHANGES

* **provider:** cut Provider API 11 registration baseline ([#164](https://github.com/RocketsAreNostalgic/ran-booster/issues/164))

### Features

* **provider:** cut Provider API 11 registration baseline ([#164](https://github.com/RocketsAreNostalgic/ran-booster/issues/164)) ([b9ce6b5](https://github.com/RocketsAreNostalgic/ran-booster/commit/b9ce6b5a571ae0a199b2b0137a83de5ea8473d1d))


### Bug Fixes

* centralize repository-relative package path policy ([#141](https://github.com/RocketsAreNostalgic/ran-booster/issues/141)) ([3dce645](https://github.com/RocketsAreNostalgic/ran-booster/commit/3dce645de43a13c7260dc08c8ddabe8b0931014b))
* enforce WordPress compatibility floor ([4e48739](https://github.com/RocketsAreNostalgic/ran-booster/commit/4e4873988de19ffeb0d934b97da9b0fe67e3f509))
* finalize provider state custody ([#155](https://github.com/RocketsAreNostalgic/ran-booster/issues/155)) ([856e228](https://github.com/RocketsAreNostalgic/ran-booster/commit/856e2288554447606003ebac5911eff2c6418415))
* move release artifact custody into Core ([#143](https://github.com/RocketsAreNostalgic/ran-booster/issues/143)) ([891ef2b](https://github.com/RocketsAreNostalgic/ran-booster/commit/891ef2b33d1d50ef03e2c9f6ad170b19ac68b081))
* reconcile signed release merge lineage ([e45b196](https://github.com/RocketsAreNostalgic/ran-booster/commit/e45b196d386d298c2309079c27fb918afb846fd1))
* remove prerelease local persistence compatibility ([#162](https://github.com/RocketsAreNostalgic/ran-booster/issues/162)) ([27ce37c](https://github.com/RocketsAreNostalgic/ran-booster/commit/27ce37c7eac89eddaf1194122f72874515d3ec84))
* remove release updater argument downgrade ([#163](https://github.com/RocketsAreNostalgic/ran-booster/issues/163)) ([e82529c](https://github.com/RocketsAreNostalgic/ran-booster/commit/e82529c806bcec0337ea5981946c2acef37f3835))
* validate release readiness before merge ([99de67e](https://github.com/RocketsAreNostalgic/ran-booster/commit/99de67e7736f27d4a8e8ec3329fdde9fc1abd453))
* validate release readiness before merge ([b1d7a33](https://github.com/RocketsAreNostalgic/ran-booster/commit/b1d7a332d91982f9802d67f5e7dc9990fa6c7c67))

## [1.0.0-beta.28](https://github.com/RocketsAreNostalgic/ran-booster/compare/v1.0.0-beta.27...v1.0.0-beta.28) (2026-09-03)


### Features

* **admin:** add repository integration detail ([3f2e744](https://github.com/RocketsAreNostalgic/ran-booster/commit/3f2e7447e101adb7b2ffcafdc72c3041604a969e))
* **admin:** add repository integration views ([b05b2e7](https://github.com/RocketsAreNostalgic/ran-booster/commit/b05b2e78fa23759db62abb86aa682417955f0ffa))
* **admin:** align package setup disclosures ([528430b](https://github.com/RocketsAreNostalgic/ran-booster/commit/528430bcfc72ef62a9ba6d271a24a96d88471fa5))
* **admin:** expose repository release automation ([7fadad1](https://github.com/RocketsAreNostalgic/ran-booster/commit/7fadad196441d804decacac8b05b0f861ee6f07e))
* **admin:** refine package source workflows ([894897c](https://github.com/RocketsAreNostalgic/ran-booster/commit/894897ce55e4a6d64932285f17f2dde4a76283b5))
* **admin:** route repository release views ([6086ff3](https://github.com/RocketsAreNostalgic/ran-booster/commit/6086ff374e7259f184b01501f73c5034de8e9ac1))
* **admin:** simplify provider repository inventory ([3fcf98f](https://github.com/RocketsAreNostalgic/ran-booster/commit/3fcf98f31b9c6099636b2f6e0b64a79f3859caa7))
* **admin:** split release readiness ownership ([381f02a](https://github.com/RocketsAreNostalgic/ran-booster/commit/381f02adda260a82131f69f72a223dff51173216))
* **admin:** summarize active package source ([fef79b3](https://github.com/RocketsAreNostalgic/ran-booster/commit/fef79b3ab34a6583277798e8055d75a59c5bc065))
* **admin:** unify provider repository projections ([f830427](https://github.com/RocketsAreNostalgic/ran-booster/commit/f83042793d4d8be1962db46a0a7e0e085ddf038e))
* centralize repository webhook and release workflows ([a42edc3](https://github.com/RocketsAreNostalgic/ran-booster/commit/a42edc34f82437eadefd6fef963fa3a7a89d20f1))
* consolidate repository management views ([aa26357](https://github.com/RocketsAreNostalgic/ran-booster/commit/aa2635769dd0ff57aae7313f21482565093d899c))
* enforce repository exclusivity and neutralize release workflows ([1865e83](https://github.com/RocketsAreNostalgic/ran-booster/commit/1865e8368b6d9d13e424aaeecb336cc88bcbae03))
* establish translation catalogue gate ([cd3b69e](https://github.com/RocketsAreNostalgic/ran-booster/commit/cd3b69e5fa204cc76b20b92371e884b55d0fcd95))
* hard cut Core to neutral updater ([1191b48](https://github.com/RocketsAreNostalgic/ran-booster/commit/1191b48b8c8c7ca9b7f4b22759b7c3e34b2dcc1e))
* **i18n:** localize discarded storage reasons ([92d9d7d](https://github.com/RocketsAreNostalgic/ran-booster/commit/92d9d7da88db7adf4676c431c1bb7fa71ac789b2))
* **i18n:** localize extension catalogue ([ae60782](https://github.com/RocketsAreNostalgic/ran-booster/commit/ae60782e5efe11b154b2e02fa8e795452b22be3b))
* **i18n:** localize provider modal copy ([19c9c17](https://github.com/RocketsAreNostalgic/ran-booster/commit/19c9c17f9c8ba4bd48dcbdc5130b88cef59b1e9d))
* **i18n:** localize storage action fallbacks ([a4738a3](https://github.com/RocketsAreNostalgic/ran-booster/commit/a4738a39ee22bc3a347fa53bcba65c1bfe19d72e))
* **i18n:** localize storage diagnostics ([bdb40f9](https://github.com/RocketsAreNostalgic/ran-booster/commit/bdb40f988caa213783dda6f5a791167c2c1c6573))
* **i18n:** localize storage setup feedback ([84e7208](https://github.com/RocketsAreNostalgic/ran-booster/commit/84e7208ec27cb0f334722707fa4fc08bb9bdc79d))
* **i18n:** localize wp-config write failures ([4652d92](https://github.com/RocketsAreNostalgic/ran-booster/commit/4652d925d0e327a74fd5f66692ff0da5c81601d8))
* **i18n:** make Core administration translatable ([c30e072](https://github.com/RocketsAreNostalgic/ran-booster/commit/c30e0721bd709017129ad140f09d31bdef143a3f))
* **i18n:** translate admin JavaScript controls ([59eb301](https://github.com/RocketsAreNostalgic/ran-booster/commit/59eb301ef94814c3886d8d288e7537de965c00e2))
* localise core administration feedback ([334d4a7](https://github.com/RocketsAreNostalgic/ran-booster/commit/334d4a7264e66123b4f7a9a524383d46a20b0c0c))
* localise credential administration feedback ([cb78664](https://github.com/RocketsAreNostalgic/ran-booster/commit/cb786646b9d7b700d05298476fef28b74e7b1ae4))
* **provider:** neutralize release workflow management ([d158460](https://github.com/RocketsAreNostalgic/ran-booster/commit/d158460e746d951341c90e41176de4a48b962b36))
* refine package source settings ([0cb937f](https://github.com/RocketsAreNostalgic/ran-booster/commit/0cb937f10a8f1ab1d9e502506ef12d6b7249b2fb))
* **release:** install the current managed offer ([25744fb](https://github.com/RocketsAreNostalgic/ran-booster/commit/25744fb0674ac1feda24510e22e3e04ff2538418))
* **releases:** move workflow setup to repositories ([cbcb500](https://github.com/RocketsAreNostalgic/ran-booster/commit/cbcb5006207b49614779eb32923ed691dac721ee))
* **releases:** use saved GitHub credentials ([29b6523](https://github.com/RocketsAreNostalgic/ran-booster/commit/29b652360789446952214bd6720c970465bb3d71))
* **webhooks:** centralize repository setup and verification ([94dfc7e](https://github.com/RocketsAreNostalgic/ran-booster/commit/94dfc7e96cc29680c62c547c95f8e68f12e03523))
* wire translation loading and archive proof ([32ed6d0](https://github.com/RocketsAreNostalgic/ran-booster/commit/32ed6d0ff493aa56ccade7e6ba4e414a43c4a081))


### Bug Fixes

* address provider workflow review feedback ([328166b](https://github.com/RocketsAreNostalgic/ran-booster/commit/328166b183f01b241f2c2b3838989a5abda4eadf))
* address release workflow rereview findings ([b7ae073](https://github.com/RocketsAreNostalgic/ran-booster/commit/b7ae07366898a0a9ff640ad9698f330481bd35f3))
* adjust styling for troubleshooting attempt rows ([231b5d1](https://github.com/RocketsAreNostalgic/ran-booster/commit/231b5d1ecd930ca0d2898aaaa8dd2a903322a10a))
* **admin:** address repository integration review feedback ([e8d76f4](https://github.com/RocketsAreNostalgic/ran-booster/commit/e8d76f4046546e56815b2b71c7d0c1a3c3ff71ef))
* **admin:** align package source tabs ([9fdcc30](https://github.com/RocketsAreNostalgic/ran-booster/commit/9fdcc304d45e7bad6299eae646d06de14ee00271))
* **admin:** align release readiness fallback ([a1587aa](https://github.com/RocketsAreNostalgic/ran-booster/commit/a1587aa9e3b983ca8b5d6bdc2d5d8b792fb61659))
* **admin:** align release source terminology ([2176d70](https://github.com/RocketsAreNostalgic/ran-booster/commit/2176d70d63024e536bfb402ace7d09ccf31c595a))
* **admin:** align release terminology ([59d4170](https://github.com/RocketsAreNostalgic/ran-booster/commit/59d417095a55c62024e9ca8b2c67d2945fc8a653))
* **admin:** align repository integration terminology ([22997f3](https://github.com/RocketsAreNostalgic/ran-booster/commit/22997f358469c6998fc9a5af59d2eb6e7e4fc0e9))
* **admin:** align webhook fallback terminology ([d25c188](https://github.com/RocketsAreNostalgic/ran-booster/commit/d25c188c88f3345377346f4be99ee30a92e91174))
* **admin:** avoid edit-only URLs on package install screens ([b3da0f2](https://github.com/RocketsAreNostalgic/ran-booster/commit/b3da0f237264ee86abfa91ac90c87d928adad0ad))
* **admin:** bind webhook package returns ([ded5f19](https://github.com/RocketsAreNostalgic/ran-booster/commit/ded5f19d842b2f111b82fadc4e334c953aaf5917))
* **admin:** centralize release automation management ([6b964ea](https://github.com/RocketsAreNostalgic/ran-booster/commit/6b964ea38286a930f3c6cffebd2e56d710aa4422))
* **admin:** checkpoint repository integration rehearsal ([e1364c9](https://github.com/RocketsAreNostalgic/ran-booster/commit/e1364c9608017c0bea0351fc098eee2f79f1087e))
* **admin:** clarify incomplete repository status ([9bc70be](https://github.com/RocketsAreNostalgic/ran-booster/commit/9bc70be37eebb5f5c78d1dd9a2cd4a579786ee04))
* **admin:** clarify package source navigation ([ef31799](https://github.com/RocketsAreNostalgic/ran-booster/commit/ef317994dba4b645a8c55c4bc43132274e6f54da))
* **admin:** clarify published release readiness ([2c51e6f](https://github.com/RocketsAreNostalgic/ran-booster/commit/2c51e6f5dcdeacd3487eed99010afdaecc024468))
* **admin:** clarify release track controls ([8e04ea3](https://github.com/RocketsAreNostalgic/ran-booster/commit/8e04ea3631fb39ac8b04b7548218ebaeab39d5b4))
* **admin:** clarify repository source tabs ([f945b6c](https://github.com/RocketsAreNostalgic/ran-booster/commit/f945b6cdf42b0ce19222eedafbe6edd87fc7b72a))
* **admin:** clarify unchecked repository subdirectories ([a97dfd7](https://github.com/RocketsAreNostalgic/ran-booster/commit/a97dfd73199be4bdca7dae8c5cf9a80dd3f3df65))
* **admin:** classify release history by metadata ([d5b9b9a](https://github.com/RocketsAreNostalgic/ran-booster/commit/d5b9b9a1ca42366083fd7cb7a6cb5713884e042c))
* **admin:** clean workflow result URLs ([3e3c682](https://github.com/RocketsAreNostalgic/ran-booster/commit/3e3c68215986fec8653750135b14a5817153c154))
* **admin:** complete repository inventory review repairs ([bfacec7](https://github.com/RocketsAreNostalgic/ran-booster/commit/bfacec76b200851d0a52d8b33a0d7965d48a0469))
* **admin:** complete terminology review ([dd74ec9](https://github.com/RocketsAreNostalgic/ran-booster/commit/dd74ec9ab5c541cab0532cb51e70ba1b0f8ccc34))
* **admin:** complete update-source terminology ([54feaf8](https://github.com/RocketsAreNostalgic/ran-booster/commit/54feaf8b6ab19ebc2ad5ffe7da752ffd3136baed))
* **admin:** contain repository extension failures ([81786c2](https://github.com/RocketsAreNostalgic/ran-booster/commit/81786c2228055fd229a450353fa2d8eb279af918))
* **admin:** contain repository extension failures ([874bc08](https://github.com/RocketsAreNostalgic/ran-booster/commit/874bc08d43b0b30d2b8e9eb7c53ce04f1316065b))
* **admin:** contain repository provider panels ([75af9f2](https://github.com/RocketsAreNostalgic/ran-booster/commit/75af9f2f22fc27cd485655cd73b81534abeef644))
* **admin:** default missing package edit context ([b41c805](https://github.com/RocketsAreNostalgic/ran-booster/commit/b41c80555cc69c5263a4aedad412c694cf7bf326))
* **admin:** fence credential evidence and public checks ([c372fd8](https://github.com/RocketsAreNostalgic/ran-booster/commit/c372fd80316fe12dec051f315d5bfea1acdabcb5))
* **admin:** gate provider automation operations ([f7900e0](https://github.com/RocketsAreNostalgic/ran-booster/commit/f7900e07e854d55f26336ce1d4b2324c0777206c))
* **admin:** group package settings actions ([a008bed](https://github.com/RocketsAreNostalgic/ran-booster/commit/a008bed85671dcd6a7877aa926bf14073ac3db86))
* **admin:** group package settings actions ([a532140](https://github.com/RocketsAreNostalgic/ran-booster/commit/a5321402759e913087b3399c9f2d69770502cdb6))
* **admin:** harden rehearsal package settings ([f01e0ef](https://github.com/RocketsAreNostalgic/ran-booster/commit/f01e0efb31d7e75e2dfd4e41804c3619b0f98f25))
* **admin:** harden repository branch checks ([24a6463](https://github.com/RocketsAreNostalgic/ran-booster/commit/24a6463396f9df04fe7134e2086e72871146a4ce))
* **admin:** harden repository integration evidence ([dfbb8c8](https://github.com/RocketsAreNostalgic/ran-booster/commit/dfbb8c8adca976d6359cebe9a2e500533a706dfc))
* **admin:** hide saved readiness on create ([dcfcf1a](https://github.com/RocketsAreNostalgic/ran-booster/commit/dcfcf1a9981a137ad75dfccd71d0071475a15d8e))
* **admin:** isolate nested release guidance ([c0fde53](https://github.com/RocketsAreNostalgic/ran-booster/commit/c0fde53c0541c1569ea396262e6b263ec292e97a))
* **admin:** keep branch evidence truthful ([296246e](https://github.com/RocketsAreNostalgic/ran-booster/commit/296246e8f24cd0666bb4ce666d0ad88312c67e90))
* **admin:** keep historical actions review-only ([92e7961](https://github.com/RocketsAreNostalgic/ran-booster/commit/92e7961628947cbb7f60e0b28994f39191030123))
* **admin:** keep multisite package routes in network admin ([7553723](https://github.com/RocketsAreNostalgic/ran-booster/commit/7553723105a3c89e8c3ae3ffcf4b16cfb2b9fef6))
* **admin:** keep release automation discoverable ([472a8fd](https://github.com/RocketsAreNostalgic/ran-booster/commit/472a8fdad999799fb66bbc646cea1ea4ac66cc60))
* **admin:** keep release links in network admin ([e0cf811](https://github.com/RocketsAreNostalgic/ran-booster/commit/e0cf811f7d01ba37cce21e72faeb32349c793547))
* **admin:** keep repository controls actionable ([65097be](https://github.com/RocketsAreNostalgic/ran-booster/commit/65097be1a7059cd1819dd80050281a464fb9041c))
* **admin:** keep repository states truthful ([d872f1a](https://github.com/RocketsAreNostalgic/ran-booster/commit/d872f1ac1de67a8913c12e6dc1d8325b66a36a36))
* **admin:** keep webhook routes in network admin ([85e5353](https://github.com/RocketsAreNostalgic/ran-booster/commit/85e5353b90ee08783b84bd19fe9ee674fde0100f))
* **admin:** normalize projected subdirectories ([299b3f9](https://github.com/RocketsAreNostalgic/ran-booster/commit/299b3f9d42a8e6588b02ec5853cfe18786fcc4d4))
* **admin:** normalize repository fallbacks ([19caab4](https://github.com/RocketsAreNostalgic/ran-booster/commit/19caab494d696052a7946e53950d1df97647370e))
* **admin:** polish repository navigation ([e4c27f0](https://github.com/RocketsAreNostalgic/ran-booster/commit/e4c27f0620eebb3dc865b4b66d11af6b341b9c53))
* **admin:** preserve actionable workflow failures ([0a4b492](https://github.com/RocketsAreNostalgic/ran-booster/commit/0a4b4928ba8c93e77d103afa626afc89002db08f))
* **admin:** preserve exact integration outcomes ([2a0153e](https://github.com/RocketsAreNostalgic/ran-booster/commit/2a0153e5ae77f53f680634e9b39650fd31a3d4e2))
* **admin:** preserve historical action contracts ([9db1db9](https://github.com/RocketsAreNostalgic/ran-booster/commit/9db1db9994cc6e74f168f10e7597553696513724))
* **admin:** preserve integration truth ([7bff2a0](https://github.com/RocketsAreNostalgic/ran-booster/commit/7bff2a0dd2858a4996bd6e10c4761c0e93e80a7b))
* **admin:** preserve mixed repository release state ([bc010d3](https://github.com/RocketsAreNostalgic/ran-booster/commit/bc010d33f9d2daa16aadfe044fb9e9112c3d628b))
* **admin:** preserve provider release intent ([e036a04](https://github.com/RocketsAreNostalgic/ran-booster/commit/e036a04a99a34eb94d22263d391610373ec05a6b))
* **admin:** preserve repository integration state ([29f2e52](https://github.com/RocketsAreNostalgic/ran-booster/commit/29f2e52a94cde7648533f13b9de7d15f65fa4300))
* **admin:** preserve repository navigation diagnostics ([7afb22e](https://github.com/RocketsAreNostalgic/ran-booster/commit/7afb22ea504e4d10b2a84901807f0329558d2d3b))
* **admin:** preserve stable workflow management shells ([5ddbfe5](https://github.com/RocketsAreNostalgic/ran-booster/commit/5ddbfe5eaea4b9faafe117d3c0e59f8f3449cec7))
* **admin:** preserve unavailable integration states ([d12d32f](https://github.com/RocketsAreNostalgic/ran-booster/commit/d12d32f8cc31d78f3a9686b926a16bdbf19b54a9))
* **admin:** prevent nested release selection ([794ce21](https://github.com/RocketsAreNostalgic/ran-booster/commit/794ce2194e016244545b1278b029750759a3ae25))
* **admin:** project repository package references ([7281b44](https://github.com/RocketsAreNostalgic/ran-booster/commit/7281b443f47d45f15fca3369b2a76be567162bb8))
* **admin:** project repository package references ([4d6ee7d](https://github.com/RocketsAreNostalgic/ran-booster/commit/4d6ee7d86f7579cd2d24bbc4a927891048b898db))
* **admin:** promote repository release automation ([b730ee9](https://github.com/RocketsAreNostalgic/ran-booster/commit/b730ee9f515b8a70b739afb91765dc02363f9964))
* **admin:** recognize established release automation ([9481b97](https://github.com/RocketsAreNostalgic/ran-booster/commit/9481b97ac0e12e0024f1e972d3b83fe7431487a8))
* **admin:** refine rehearsal package controls ([7eb3a60](https://github.com/RocketsAreNostalgic/ran-booster/commit/7eb3a60b9834065c1b32ebd332b9683e219425d9))
* **admin:** remove stray release workflow markup ([609c3ca](https://github.com/RocketsAreNostalgic/ran-booster/commit/609c3ca9c92411b9cfbbefeac40ee1f356d2b748))
* **admin:** render provider-neutral release automation ([991d0e3](https://github.com/RocketsAreNostalgic/ran-booster/commit/991d0e3fadb761b7149f2d6ce649056f57d09301))
* **admin:** report partial release inventories accurately ([cdf3cf9](https://github.com/RocketsAreNostalgic/ran-booster/commit/cdf3cf9235ff6f5a98f6bf21500360374c5f806d))
* **admin:** reserve core repository details ([043c5ab](https://github.com/RocketsAreNostalgic/ran-booster/commit/043c5ab59a1696190b1540aa59ae0bf1d19b7ce7))
* **admin:** resolve repository inventory review ([de6ab90](https://github.com/RocketsAreNostalgic/ran-booster/commit/de6ab900db49306783888fd3707caca54d5bb1df))
* **admin:** restore branch readiness shell ([cdea90c](https://github.com/RocketsAreNostalgic/ran-booster/commit/cdea90cb38994e8cfd53ca469f13466bc4566199))
* **admin:** retain blocked webhook context ([5064353](https://github.com/RocketsAreNostalgic/ran-booster/commit/50643535bf9d1ada560ed868498d9a9c03a1316b))
* **admin:** retain complete webhook history ([aba3637](https://github.com/RocketsAreNostalgic/ran-booster/commit/aba363742a6aae414c8ba2bc6510653e74a77944))
* **admin:** retain historical webhook evidence ([4221797](https://github.com/RocketsAreNostalgic/ran-booster/commit/4221797c880305641519ff596dfad6356c2dd49a))
* **admin:** retain inactive branch readiness ([35a8f84](https://github.com/RocketsAreNostalgic/ran-booster/commit/35a8f84871a5e594abb0717603358f2ffae0db90))
* **admin:** retain inactive branch readiness ([61c0a37](https://github.com/RocketsAreNostalgic/ran-booster/commit/61c0a37a6ce652e95c4fbbc2a31ddba8170599a9))
* **admin:** retain integration state consistently ([70a5b0d](https://github.com/RocketsAreNostalgic/ran-booster/commit/70a5b0d3a56ea091c2abdf5213f713fa114f6b0a))
* **admin:** retire stale repository evidence ([62fcd29](https://github.com/RocketsAreNostalgic/ran-booster/commit/62fcd29c4a8890f68db8c69a05cfe286e3a7d12c))
* **admin:** scope release readiness guidance ([218bc8e](https://github.com/RocketsAreNostalgic/ran-booster/commit/218bc8ee98a0d7e9e9f4343129ca914403651a6c))
* **admin:** serialize branch verification ([685fcde](https://github.com/RocketsAreNostalgic/ran-booster/commit/685fcde2f8df1baaab6710b7d8581c6e0e914d5e))
* **admin:** simplify deployment retry recovery ([1831e03](https://github.com/RocketsAreNostalgic/ran-booster/commit/1831e0315e478bdc4eb4c5ee9f3d30c1fcc1a33d))
* **admin:** stabilize repository status markers ([2ec55e9](https://github.com/RocketsAreNostalgic/ran-booster/commit/2ec55e9979cfd07842d567bcfac135502d1b1384))
* **admin:** verify saved repository branches ([c5732ed](https://github.com/RocketsAreNostalgic/ran-booster/commit/c5732ed6cc9e8a981c09a143929f7544bb031237))
* admit disposable localisation test locale ([0ab2296](https://github.com/RocketsAreNostalgic/ran-booster/commit/0ab22968d2b8f751b6d584b4cab891472cb41bec))
* checkpoint repository exclusivity and rehearsal refinements ([f5a88d5](https://github.com/RocketsAreNostalgic/ran-booster/commit/f5a88d52f810d3c77ca5c2d23fc82a495f1a8abe))
* **core:** close deployment evidence races ([1cc473d](https://github.com/RocketsAreNostalgic/ran-booster/commit/1cc473d2c419f4ac0183e2cf084c0c9e086078d8))
* css readiness icons ([5fbf4cb](https://github.com/RocketsAreNostalgic/ran-booster/commit/5fbf4cbe6a28a79618623389c5cf0d97a30f549a))
* **deployment:** accept verified WordPress update results ([8616274](https://github.com/RocketsAreNostalgic/ran-booster/commit/86162741718e5e0b0c86d69742e1ee97d1b8aa36))
* **deployment:** prevent nested automatic updates ([4cb8204](https://github.com/RocketsAreNostalgic/ran-booster/commit/4cb82049bdd89770905aa5e53ee102c5894c9bb0))
* **deployment:** reconcile restored managed packages ([175ad90](https://github.com/RocketsAreNostalgic/ran-booster/commit/175ad9046307d344e1806f4a5b70a00e75f37209))
* **deployment:** treat managed destinations as no-op ([e4fa52d](https://github.com/RocketsAreNostalgic/ran-booster/commit/e4fa52dc8dc0b7b5bb061571b7b15de0902afeb4))
* enforce release-source subdirectory invariants ([3441877](https://github.com/RocketsAreNostalgic/ran-booster/commit/3441877aad4bf5692ce893ccbbc182a25ffde912))
* **github:** revalidate managed release workflows ([d468a69](https://github.com/RocketsAreNostalgic/ran-booster/commit/d468a692d382d9c91c545b78ed17364d4a60b8b7))
* **github:** verify generated release contract ([17930d7](https://github.com/RocketsAreNostalgic/ran-booster/commit/17930d7c04344d6eea038cb3301bfbfa6e0cfd7c))
* guard Core custody cleanup identity ([eccee65](https://github.com/RocketsAreNostalgic/ran-booster/commit/eccee658ae839ff7b0c5b2d8a51fe5eb1df27e49))
* guard release workflow provider metadata ([d40e95b](https://github.com/RocketsAreNostalgic/ran-booster/commit/d40e95b54c9c5f6108a3285ef6920d6c308f8598))
* harden core release target bootstrap ([58a848a](https://github.com/RocketsAreNostalgic/ran-booster/commit/58a848a3e03aca7d20321ff44869574c0dc4e8d7))
* **i18n:** address remaining review feedback ([af89ca2](https://github.com/RocketsAreNostalgic/ran-booster/commit/af89ca2017c4eb0214e18d3da51382eb5a9c098c))
* **i18n:** clarify wp-config file failures ([2e97c5c](https://github.com/RocketsAreNostalgic/ran-booster/commit/2e97c5cb2cd41aa5db0033ea023ec7120bc2d752))
* **i18n:** close localisation review gaps ([4a112d6](https://github.com/RocketsAreNostalgic/ran-booster/commit/4a112d662bd12f5d14195f0b05c70fcd97fce4b4))
* **i18n:** localize admin guidance labels ([abdaea4](https://github.com/RocketsAreNostalgic/ran-booster/commit/abdaea45db9bc2306b2b5da5b64d14b97a90c5bf))
* **i18n:** localize package form fields ([66e68dd](https://github.com/RocketsAreNostalgic/ran-booster/commit/66e68dd9cda15b47947a584d9748a12e68c3b50d))
* **i18n:** localize package navigation ([f449145](https://github.com/RocketsAreNostalgic/ran-booster/commit/f44914591c10df17b188db04f52e2db3796f6a32))
* **i18n:** localize webhook notices ([ef9fce7](https://github.com/RocketsAreNostalgic/ran-booster/commit/ef9fce789ecd1da5822fb1fca2d35f333580e084))
* **i18n:** preserve modal translation placeholders ([de23984](https://github.com/RocketsAreNostalgic/ran-booster/commit/de23984141f8ef535fbbe855306cc0c6952764f9))
* **i18n:** retain WP-CLI Phar support ([1c9e60a](https://github.com/RocketsAreNostalgic/ran-booster/commit/1c9e60abdf4d37ecc49e1590e85f56f55f31f7b8))
* **i18n:** select a compatible WP-CLI runtime ([00c867c](https://github.com/RocketsAreNostalgic/ran-booster/commit/00c867c4da1d43fbea734f317cfc8897e307407c))
* **i18n:** translate repository placeholder ([030800b](https://github.com/RocketsAreNostalgic/ran-booster/commit/030800bd353a8a4597b4340ab94c796850bc3082))
* **integrations:** harden release workflow boundaries ([d8bd88f](https://github.com/RocketsAreNostalgic/ran-booster/commit/d8bd88f14a2eae54e9fd71adfc788d740ce61dba))
* preserve opaque prospective release IDs ([43aa4c8](https://github.com/RocketsAreNostalgic/ran-booster/commit/43aa4c8b8a6cd259838926eb1382e5a963d00e0a))
* preserve unavailable repository source state ([87b5729](https://github.com/RocketsAreNostalgic/ran-booster/commit/87b572945557d049825ff4820c957fec00108b98))
* preserve updater policy boundaries ([78cd5d3](https://github.com/RocketsAreNostalgic/ran-booster/commit/78cd5d372e1911aec4eed9da9b084e30171483b9))
* project neutral updater status ([85a5ae4](https://github.com/RocketsAreNostalgic/ran-booster/commit/85a5ae4bdb908a19c74d8bbd5a90df8e370f8c84))
* **provider:** reject malformed workflow text ([75cbebb](https://github.com/RocketsAreNostalgic/ran-booster/commit/75cbebb6d7b6b44fcbf054d452fca39ba19fa480))
* **providers:** reject blank workflow guidance ([9760d38](https://github.com/RocketsAreNostalgic/ran-booster/commit/9760d388796ba065a58453aecdd03e703fb5344c))
* provision locked updater source in CI ([958a5b4](https://github.com/RocketsAreNostalgic/ran-booster/commit/958a5b40164a3b4912e02d0608559bd9e5f4e4eb))
* provision updater source for quality ([d6d6326](https://github.com/RocketsAreNostalgic/ran-booster/commit/d6d6326d36eb163434fd6267c64c9d6d2b70db99))
* **release:** align neutral updater beta identity ([debd0d0](https://github.com/RocketsAreNostalgic/ran-booster/commit/debd0d0186316e03360e08d509f8edcbd7600b48))
* **release:** align neutral updater beta identity ([a7cbb90](https://github.com/RocketsAreNostalgic/ran-booster/commit/a7cbb90332a6f75578eb26917a77bb74d692621e))
* **release:** bind native updates to exact offers ([7df3e02](https://github.com/RocketsAreNostalgic/ran-booster/commit/7df3e02b139bdc01dd8cab32e595297353506ca0))
* **release:** clear failed workflow claims ([9dadad9](https://github.com/RocketsAreNostalgic/ran-booster/commit/9dadad99561fdb76253c24e94cd37347806b5e74))
* **release:** guard every workflow record write ([2dcbb61](https://github.com/RocketsAreNostalgic/ran-booster/commit/2dcbb6136098d4ccbc705ee501a4cffa11accff7))
* **release:** harden nested-source quarantine ([7375bd6](https://github.com/RocketsAreNostalgic/ran-booster/commit/7375bd636dfaf48200e22d8067755f2238dc954d))
* **release:** harden workflow review states ([1c4edfe](https://github.com/RocketsAreNostalgic/ran-booster/commit/1c4edfe7ba133c91f5ddcdfcad7e980be064e2ed))
* **release:** preserve repository admission failures ([cb344e3](https://github.com/RocketsAreNostalgic/ran-booster/commit/cb344e3801d92536795e9577775cfbbf7024ca0a))
* **release:** recover abandoned workflow claims ([63db40c](https://github.com/RocketsAreNostalgic/ran-booster/commit/63db40c04fa47c11e6d8593a85eaaf233b613c70))
* **release:** reject nested release sources ([702fcf3](https://github.com/RocketsAreNostalgic/ran-booster/commit/702fcf33ee76bab0e345dbb82813e837fa30e0d5))
* **release:** reject unavailable workflow credentials ([bd63356](https://github.com/RocketsAreNostalgic/ran-booster/commit/bd6335621080e26d352f84ebf33bb07cd888fbfa))
* **releases:** enforce workflow result boundaries ([e831f63](https://github.com/RocketsAreNostalgic/ran-booster/commit/e831f6348e2863050e1b9526d89de85f87e6647a))
* **release:** serialize workflow draft setup ([4762f3e](https://github.com/RocketsAreNostalgic/ran-booster/commit/4762f3e16a6e7b5c3744d262d0cdb065d0ad424e))
* **release:** serialize workflow evidence history ([01cff80](https://github.com/RocketsAreNostalgic/ran-booster/commit/01cff8081df25decca44f1e2b2a5749aae96bcb9))
* **release:** serialize workflow setup safely ([51b6c19](https://github.com/RocketsAreNostalgic/ran-booster/commit/51b6c196099ce2d62bce3243bef06a3596af2965))
* **releases:** fail closed on unavailable source state ([21c5cca](https://github.com/RocketsAreNostalgic/ran-booster/commit/21c5ccab7302cee42ad788a69000ec30b11b55ce))
* **releases:** preserve blocked workflow states ([74867e2](https://github.com/RocketsAreNostalgic/ran-booster/commit/74867e2e3dc09aed1d190e2d43c6eb0b3d8f8f26))
* **releases:** preserve Core workflow evidence ([5e7171c](https://github.com/RocketsAreNostalgic/ran-booster/commit/5e7171c83a4443258b5ce67d8b158e5d26b84b3c))
* **releases:** preserve package fallback results ([695d555](https://github.com/RocketsAreNostalgic/ran-booster/commit/695d555f2958272614f4476395cf288f42870e9a))
* **releases:** preserve workflow source diagnostics ([927bc5a](https://github.com/RocketsAreNostalgic/ran-booster/commit/927bc5a32fd6e90b810cca5244ac98ac7b66e788))
* **releases:** suppress partial inventory workflow actions ([814dc6f](https://github.com/RocketsAreNostalgic/ran-booster/commit/814dc6fdb9d4ecfb87fc23a1b0e23ab7965fc3a3))
* **release:** surface workflow lock uncertainty ([7f420fb](https://github.com/RocketsAreNostalgic/ran-booster/commit/7f420fbd88c5353819093c99ace8b76a2f491e86))
* **release:** tolerate missing subdirectory input ([5e1567a](https://github.com/RocketsAreNostalgic/ran-booster/commit/5e1567a9bcfc1699c4d3ca1d0fb0bf6893a63b41))
* **removal:** invalidate evidence before unlink ([6437878](https://github.com/RocketsAreNostalgic/ran-booster/commit/64378780b79581470526a1f2c640b72330bf5b03))
* **removal:** preserve branch evidence on failure ([4c0df09](https://github.com/RocketsAreNostalgic/ran-booster/commit/4c0df09943b3b7399178598fbe381237b2387274))
* **review:** close branch readiness races ([6ed2c37](https://github.com/RocketsAreNostalgic/ran-booster/commit/6ed2c37887002f2be1c6dd37231d1a2600a92ff8))
* **review:** contain branch evidence failures ([00f5e97](https://github.com/RocketsAreNostalgic/ran-booster/commit/00f5e97d38f4da1dbb47cb56ed2d0ea5d5a64cdc))
* **review:** invalidate cached branch access checks ([515ed71](https://github.com/RocketsAreNostalgic/ran-booster/commit/515ed71dbd44b87fde459719697352c35afffd38))
* **review:** preserve branch check outcomes ([a4ee681](https://github.com/RocketsAreNostalgic/ran-booster/commit/a4ee6814e8b6ea6188be718e67bb8999e85912d6))
* **review:** stabilize repository integration projections ([caf57a0](https://github.com/RocketsAreNostalgic/ran-booster/commit/caf57a03250b739c98d614f15c56907d51f5e192))
* **storage:** preserve release root CAS ([3956021](https://github.com/RocketsAreNostalgic/ran-booster/commit/3956021771bcb2fbaaac0f400f10e08301751000))
* strengthen deployment recovery and branch readiness ([6bb1809](https://github.com/RocketsAreNostalgic/ran-booster/commit/6bb1809324725f4d5bfde27df2b6626f642296d8))
* **styles:** adjust border and padding for source choice elements ([00ae497](https://github.com/RocketsAreNostalgic/ran-booster/commit/00ae49792617be19cb4e3f89baa8c2210a5cfedb))
* **test:** honour configured temporary directory ([ad48c1f](https://github.com/RocketsAreNostalgic/ran-booster/commit/ad48c1fbba018112b6c285c074612f89ab833162))
* verify neutral updater release runtime ([2af734e](https://github.com/RocketsAreNostalgic/ran-booster/commit/2af734e8ef1351b421bd89e918c8cbbcdd224750))
* version opaque prospective release contract ([fe5575d](https://github.com/RocketsAreNostalgic/ran-booster/commit/fe5575df47f8eb43189f089540fae15faf5ebc80))
* **webhooks:** preserve authoritative management state ([f65642b](https://github.com/RocketsAreNostalgic/ran-booster/commit/f65642ba4390e606abfaf52813dc8a6f747c5378))
* **webhooks:** retain registered management controls ([077b64d](https://github.com/RocketsAreNostalgic/ran-booster/commit/077b64d1887867d7283233259524f564f26a18c5))
* **wordpress:** preserve branch update authority ([4d7c751](https://github.com/RocketsAreNostalgic/ran-booster/commit/4d7c75182b9e60eb8083768ca8710015fe9d2111))

## [1.0.0-beta.27](https://github.com/RocketsAreNostalgic/ran-booster/compare/v1.0.0-beta.26...v1.0.0-beta.27) (2026-08-22)


### Bug Fixes

* **admin:** report existing managed packages as success ([8d4bdd6](https://github.com/RocketsAreNostalgic/ran-booster/commit/8d4bdd6cdb40d0c534c80db118089fc9b5501aed))
* **admin:** report existing managed packages as success ([1d30871](https://github.com/RocketsAreNostalgic/ran-booster/commit/1d30871e87ce616db9a2651e803c292f9ba7d782))

## [1.0.0-beta.26](https://github.com/RocketsAreNostalgic/ran-booster/compare/v1.0.0-beta.25...v1.0.0-beta.26) (2026-08-21)


### Bug Fixes

* **admin:** separate update notices from shell ([b66879e](https://github.com/RocketsAreNostalgic/ran-booster/commit/b66879ed4ca3ed07849462ac639fce8bd285e275))
* **admin:** separate update notices from shell ([4595234](https://github.com/RocketsAreNostalgic/ran-booster/commit/45952348b8279b17390335ea089372424871538b))

## [1.0.0-beta.25](https://github.com/RocketsAreNostalgic/ran-booster/compare/v1.0.0-beta.24...v1.0.0-beta.25) (2026-08-21)


### Features

* **admin:** add documentation contents index ([#72](https://github.com/RocketsAreNostalgic/ran-booster/issues/72)) ([494f854](https://github.com/RocketsAreNostalgic/ran-booster/commit/494f854ce77aa87d220c4f2cfe6fe18d369101aa))


### Bug Fixes

* **updater:** correct Booster repository identity ([f5712be](https://github.com/RocketsAreNostalgic/ran-booster/commit/f5712befc1bc70a429156b501ee0daa20081aeb4))
* **updater:** correct Booster repository identity ([ccc0535](https://github.com/RocketsAreNostalgic/ran-booster/commit/ccc0535883ade7b00f65a2c7f2e51b38d423e3a6))

## [1.0.0-beta.24](https://github.com/RocketsAreNostalgic/ran-booster/compare/v1.0.0-beta.23...v1.0.0-beta.24) (2026-08-21)


### ⚠ BREAKING CHANGES

* activate bundled release administration
* **provider:** remove obsolete release discovery
* **provider:** require exact capability facets
* bundle GitHub webhook management in Core ([#34](https://github.com/RocketsAreNostalgic/ran-booster/issues/34))
* adopt updater-owned native artifact claims
* adopt shared updater authority contract ([#4](https://github.com/RocketsAreNostalgic/ran-booster/issues/4))

### Features

* activate bundled release administration ([48bdf97](https://github.com/RocketsAreNostalgic/ran-booster/commit/48bdf97426d03f1c32176129b1818b6edfcf9192))
* add explicit transporter credential export ([8a43d3f](https://github.com/RocketsAreNostalgic/ran-booster/commit/8a43d3f9e2f5392a620f8e475260030805ab9e47))
* add explicit transporter credential import ([1c217e2](https://github.com/RocketsAreNostalgic/ran-booster/commit/1c217e25b066e0cc564e692a326cb643aab16650))
* add fixed provider webhook operations ([f644757](https://github.com/RocketsAreNostalgic/ran-booster/commit/f6447577b6b2783ed28935c21fb21463da4b78b0))
* add repository webhook detail routes ([6318077](https://github.com/RocketsAreNostalgic/ran-booster/commit/63180776fadb8da073678abe9b05230ba2c73b60))
* **admin:** adopt shared admin shell ([#66](https://github.com/RocketsAreNostalgic/ran-booster/issues/66)) ([766325a](https://github.com/RocketsAreNostalgic/ran-booster/commit/766325a06f37e84d3fcddf125662c12948e0b50d))
* **admin:** collapse package danger zone ([d44b43a](https://github.com/RocketsAreNostalgic/ran-booster/commit/d44b43abb8db3b143aad1e58d12ddbe157c6d5ca))
* adopt shared updater authority contract ([#4](https://github.com/RocketsAreNostalgic/ran-booster/issues/4)) ([16198f7](https://github.com/RocketsAreNostalgic/ran-booster/commit/16198f78b9a860d97a3ca5c26c670b76e5956554))
* bundle GitHub webhook management in Core ([#34](https://github.com/RocketsAreNostalgic/ran-booster/issues/34)) ([8ae76b8](https://github.com/RocketsAreNostalgic/ran-booster/commit/8ae76b88b3444a20d7e9be2e3440e109b98c4831))
* complete V3 Core and adopt updater beta.5 ([e52e42b](https://github.com/RocketsAreNostalgic/ran-booster/commit/e52e42b02ae48b868766d717ac343f10cb951700))
* complete V3 Core and adopt updater beta.5 ([#25](https://github.com/RocketsAreNostalgic/ran-booster/issues/25)) ([e52e42b](https://github.com/RocketsAreNostalgic/ran-booster/commit/e52e42b02ae48b868766d717ac343f10cb951700))
* integrate release and webhook history reads ([86db737](https://github.com/RocketsAreNostalgic/ran-booster/commit/86db737269d6f88b98e4c3caf7ff5d83ca63bbca))
* polish transporter credential review ([5bfd553](https://github.com/RocketsAreNostalgic/ran-booster/commit/5bfd5534c848e86e037d352d7f3fddff7e9b8562))
* **portability:** close explicit credential transfer controls ([2027b7c](https://github.com/RocketsAreNostalgic/ran-booster/commit/2027b7ca35b1be67f04bf1c2b8164e5b9811bdf5))
* **provider:** add exact release acquisition capability ([c02b8d0](https://github.com/RocketsAreNostalgic/ran-booster/commit/c02b8d089387292b290dca22e997d8ae8bcbac96))
* **provider:** add exact release acquisition capability ([76a25da](https://github.com/RocketsAreNostalgic/ran-booster/commit/76a25da837648f29234bf6d809948092325ea153))
* **provider:** add exact release inspection capability ([3f03df7](https://github.com/RocketsAreNostalgic/ran-booster/commit/3f03df7f2ab8f116e51543e2f4043edd8109bef7))
* **provider:** add exact release inspection capability ([8f36461](https://github.com/RocketsAreNostalgic/ran-booster/commit/8f36461925af333e6e05a3997f1d9c9f697e2269))
* **provider:** add release candidate listing capability ([38c4620](https://github.com/RocketsAreNostalgic/ran-booster/commit/38c4620bfc868b98d45a48b23bbefc2bdc533884))
* **provider:** add release candidate listing capability ([d84a7a7](https://github.com/RocketsAreNostalgic/ran-booster/commit/d84a7a756e37b16a42ad4ac2ebeeb49e3e3469e5))
* **provider:** add release metadata capability ([9c545e1](https://github.com/RocketsAreNostalgic/ran-booster/commit/9c545e1904189daf634c71368df2e07bef1bfb4a))
* **provider:** add release metadata capability ([20c0b6a](https://github.com/RocketsAreNostalgic/ran-booster/commit/20c0b6abd37f3bcc673a4f5718810b2cfe9d8fb4))
* **provider:** move native release targets behind providers ([3331117](https://github.com/RocketsAreNostalgic/ran-booster/commit/3331117dc529ec5a2732b6a52b0e80a20d10c075))
* **provider:** move native release targets behind providers ([1f8ffb8](https://github.com/RocketsAreNostalgic/ran-booster/commit/1f8ffb82224a427777797968c4477cc33698f4ce))
* **provider:** place webhook management by capability ([5bc23a1](https://github.com/RocketsAreNostalgic/ran-booster/commit/5bc23a1d811fb86247b0d0af14e043a09e58d0ab))
* **provider:** remove obsolete release discovery ([1705852](https://github.com/RocketsAreNostalgic/ran-booster/commit/1705852b1de457ee67a1da9ab85978da31d4bdfe))
* **provider:** require exact capability facets ([5c10d23](https://github.com/RocketsAreNostalgic/ran-booster/commit/5c10d2356aba227b79f52c03e3ad32e5ac6cce2c))
* **provider:** route managed release preflight through providers ([1f2c3bc](https://github.com/RocketsAreNostalgic/ran-booster/commit/1f2c3bcdbaf4fefae277f81405e15574e2c327f6))
* **provider:** route managed release preflight through providers ([53e5c21](https://github.com/RocketsAreNostalgic/ran-booster/commit/53e5c21c9e84aa40b7f586f64fd92c7c855dfabb))
* publish provider API 9 ([89d5817](https://github.com/RocketsAreNostalgic/ran-booster/commit/89d5817f9331bfe9d7ca19439f8024b0b09affda))
* publish Provider API 9 ([70ce8bc](https://github.com/RocketsAreNostalgic/ran-booster/commit/70ce8bc6453e53e33634aa3f7a6f863894d1273e))
* recover migrated credential storage ([e19ea80](https://github.com/RocketsAreNostalgic/ran-booster/commit/e19ea80affd3a18095ade0ff799e76134076026e))
* **releases:** bundle release administration successor ([44f6b78](https://github.com/RocketsAreNostalgic/ran-booster/commit/44f6b781acba080363ad165a3704c9191781adf3))
* **releases:** bundle release administration successor ([885a0da](https://github.com/RocketsAreNostalgic/ran-booster/commit/885a0da95b64661968aba89d09a7ebe052e2a60e))
* **releases:** extract private candidate read seam ([4ccdf29](https://github.com/RocketsAreNostalgic/ran-booster/commit/4ccdf29331b49c3032fe55204b27abce719468ef))
* **webhooks:** expose persisted history read ([d5ccb92](https://github.com/RocketsAreNostalgic/ran-booster/commit/d5ccb92d7953a2039f5bb3a8ad99ae16fdba838f))


### Bug Fixes

* accept exact WordPress 7.0 floor ([9b3bd48](https://github.com/RocketsAreNostalgic/ran-booster/commit/9b3bd4866ca85eb00fb750db7a4fef929a81b28c))
* **admin:** align public extension product copy ([57d00ec](https://github.com/RocketsAreNostalgic/ran-booster/commit/57d00ec58dab626dafa89279ebb3f758263a6736))
* **admin:** align release lifecycle and public extension surfaces ([443e093](https://github.com/RocketsAreNostalgic/ran-booster/commit/443e093abed3548b7d3175c36d7c789b1c044d4c))
* **admin:** bound historical webhook lookup ([e477c34](https://github.com/RocketsAreNostalgic/ran-booster/commit/e477c34b056aedaf843cd23a6f4217f5f8329931))
* **admin:** preserve exact historical release context ([19cab4d](https://github.com/RocketsAreNostalgic/ran-booster/commit/19cab4d8b4d7576e7a09c242523ad2893129c93b))
* **admin:** preserve exact historical release context ([7675f00](https://github.com/RocketsAreNostalgic/ran-booster/commit/7675f001c6739d7db90c18d46fc20cb676d532f2))
* **admin:** scope Core update notice suppression ([01df3fb](https://github.com/RocketsAreNostalgic/ran-booster/commit/01df3fb5e7c4d9ee388120d85ef4cf6361ec4327))
* adopt updater-owned native artifact claims ([4996efb](https://github.com/RocketsAreNostalgic/ran-booster/commit/4996efb8ba10979232fa9e5252156dda504b5c02))
* align automatic secrets directory setup ([4e55bf9](https://github.com/RocketsAreNostalgic/ran-booster/commit/4e55bf9fdd964861def033bbb549a517f6295c38))
* align managed package list controls ([1fc484f](https://github.com/RocketsAreNostalgic/ran-booster/commit/1fc484ff1e9f78d9a8c3bfb776c2133135860c21))
* bind GitHub through provider credential store ([1ff18cb](https://github.com/RocketsAreNostalgic/ran-booster/commit/1ff18cb447785af397ef7fd48c832a6146928811))
* **ci:** authenticate candidate fetches ([#60](https://github.com/RocketsAreNostalgic/ran-booster/issues/60)) ([24fd6b7](https://github.com/RocketsAreNostalgic/ran-booster/commit/24fd6b756aa5cfe06d58eaa0cb90982f41f4a11b))
* **ci:** verify candidate run events exactly ([#62](https://github.com/RocketsAreNostalgic/ran-booster/issues/62)) ([2821a76](https://github.com/RocketsAreNostalgic/ran-booster/commit/2821a76b9195c92851db09710af2be6e83185f9a))
* clarify guidance on using local sibling as standards authority ([a993cf2](https://github.com/RocketsAreNostalgic/ran-booster/commit/a993cf2c29cdc6d17aaafe67cffa93aa8b317c2f))
* close global and bulk secret access ([f25a09d](https://github.com/RocketsAreNostalgic/ran-booster/commit/f25a09d614aec004cc9190423db8c79a1652d3d2))
* close ordinary add-on logging contracts ([4aa72fe](https://github.com/RocketsAreNostalgic/ran-booster/commit/4aa72fefb57d9f52d70826670d6f7ea35ccca75f))
* contain package source card text ([331b4ba](https://github.com/RocketsAreNostalgic/ran-booster/commit/331b4ba7bf9f215c383e66a11ef1a0667e73af0c))
* **credentials:** make credential entry actionable ([f51db4a](https://github.com/RocketsAreNostalgic/ran-booster/commit/f51db4a1a9302b59c5c38cac490d5e2c950cb1c3))
* **credentials:** make secret validation actionable ([cc21753](https://github.com/RocketsAreNostalgic/ran-booster/commit/cc21753e3cdabfc0bd29253f657e1a945b8bb315))
* **dependencies:** add libc support for various resolver bindings in pnpm-lock.yaml ([6ea2644](https://github.com/RocketsAreNostalgic/ran-booster/commit/6ea2644d1f458add4b49828b0e7cd629e19ea593))
* **deployment:** harden archive preflight retries ([ea37584](https://github.com/RocketsAreNostalgic/ran-booster/commit/ea375847f44c2c677002de51898dea06a08905c4))
* **deps:** adopt updater beta.3 continuity fix ([3a493e8](https://github.com/RocketsAreNostalgic/ran-booster/commit/3a493e89efa5c3795245a732af92419343f03cec))
* **deps:** adopt updater beta.3 continuity fix ([4d7bb9e](https://github.com/RocketsAreNostalgic/ran-booster/commit/4d7bb9e3ee904b52591eac279818570e8571b777))
* **deps:** adopt updater beta.4 finalization fix ([3544475](https://github.com/RocketsAreNostalgic/ran-booster/commit/35444754bd24097b9ed40925a32dbf7ca2859260))
* **deps:** adopt updater beta.4 finalization fix ([3a62017](https://github.com/RocketsAreNostalgic/ran-booster/commit/3a620174b070354d2c44baec15e4c87dee55b777))
* **deps:** adopt updater beta.5 ([7b01f4d](https://github.com/RocketsAreNostalgic/ran-booster/commit/7b01f4d743c095a08a3f7966bf33d01000203315))
* expose release validation diagnostics ([fcd1951](https://github.com/RocketsAreNostalgic/ran-booster/commit/fcd19517cc3ad8c151c42afd80d4fef283ec0689))
* expose secrets storage failures ([e50308e](https://github.com/RocketsAreNostalgic/ran-booster/commit/e50308e46a00dcfa50880f7549e0ec92fc9decd9))
* formalize provider credential trust ([54c52ef](https://github.com/RocketsAreNostalgic/ran-booster/commit/54c52ef2f634971753995aa2f3c042b5d103518a))
* harden provider webhook operation recovery ([aa2f870](https://github.com/RocketsAreNostalgic/ran-booster/commit/aa2f87091367089926452670c66d6696281c151d))
* harden webhook request admission ([945f480](https://github.com/RocketsAreNostalgic/ran-booster/commit/945f480908330d7a18f718e4084a850284a53296))
* identify Transporter package results ([4b4ea5d](https://github.com/RocketsAreNostalgic/ran-booster/commit/4b4ea5ddfc287b465a4503206088718c2dfd3135))
* import Blueprint credentials for managed packages ([62fb097](https://github.com/RocketsAreNostalgic/ran-booster/commit/62fb097f62e44e92033594b10cb0a046eeafcf5a))
* **interaction:** enhance response handling for provider profile save operations ([e657d35](https://github.com/RocketsAreNostalgic/ran-booster/commit/e657d359ab82ed43e8ebb2f8ee8b6eccbe549c05))
* keep development notice styling consistent ([30e1ba7](https://github.com/RocketsAreNostalgic/ran-booster/commit/30e1ba7e8a2913a242c4952f0cf3c03db647d857))
* make secrets storage actionable ([c9d0f26](https://github.com/RocketsAreNostalgic/ran-booster/commit/c9d0f26991073c7750dbb942635243f7d9feb35e))
* make secrets storage failures actionable ([912b85c](https://github.com/RocketsAreNostalgic/ran-booster/commit/912b85cef47099c55531d9be795160ab01c8931f))
* open advanced settings from webhook rows ([d36ae82](https://github.com/RocketsAreNostalgic/ran-booster/commit/d36ae82d9637cf59bbe172903a59389857e9ccf7))
* pass Core repository identity to updater ([a7763f4](https://github.com/RocketsAreNostalgic/ran-booster/commit/a7763f40cc03b6f97022653ec5e8ae513860c8bb))
* preserve webhook recovery evidence ([88391ef](https://github.com/RocketsAreNostalgic/ran-booster/commit/88391efb6bc2859a895ff947ec0afe6d9ea29f30))
* **provider:** complete webhook management admission ([725d15c](https://github.com/RocketsAreNostalgic/ran-booster/commit/725d15ca4819d1e9b6dd22d227c5f5ef36e07683))
* **provider:** contain partial release capabilities ([d94d0be](https://github.com/RocketsAreNostalgic/ran-booster/commit/d94d0be65b22a7cbe41c452bdf221c8e0fd33f08))
* **provider:** fail closed on missing artifact cleanup ([236e4ac](https://github.com/RocketsAreNostalgic/ran-booster/commit/236e4ac1d72efdc44e425d36a96fb260384b348c))
* **provider:** normalize metadata predicate contract ([2875ee2](https://github.com/RocketsAreNostalgic/ran-booster/commit/2875ee2634b834e6451e31ecd328eaed2d5fb148))
* **provider:** preserve release inspection semantics ([eb5c1bf](https://github.com/RocketsAreNostalgic/ran-booster/commit/eb5c1bff68b4808cead97a2ba9bec7abca6ca891))
* recover orphaned credential storage safely ([02ab40b](https://github.com/RocketsAreNostalgic/ran-booster/commit/02ab40b4f240372b48ee4535b644e808e09f9940))
* release rejected-admission ledger removal ([567c5c1](https://github.com/RocketsAreNostalgic/ran-booster/commit/567c5c1062309c496a2da0cb5f9bd46285705b83))
* release rejected-admission ledger removal ([a96437a](https://github.com/RocketsAreNostalgic/ran-booster/commit/a96437adda2fa3d678ef817acef96146ce39496d))
* **release:** allow false label state ([1015315](https://github.com/RocketsAreNostalgic/ran-booster/commit/10153151b208aac2341e2a826278643bfcfd0967))
* **release:** allow PR label mutation ([0cd2b93](https://github.com/RocketsAreNostalgic/ran-booster/commit/0cd2b937ac9ce8e337d09077deee4fe9ee89016b))
* **release:** authenticate candidate fetches ([cce26f9](https://github.com/RocketsAreNostalgic/ran-booster/commit/cce26f9e10e15e484e2548da452a9fe0be2dfcff))
* **release:** authenticate candidate fetches ([a08a14a](https://github.com/RocketsAreNostalgic/ran-booster/commit/a08a14ada2e0bc60d3e15aedd8705fa417fe5bbb))
* **release:** defer external native enablement ([2d4be8d](https://github.com/RocketsAreNostalgic/ran-booster/commit/2d4be8dc7144b74b577d704eea3bf94ab352c991))
* **release:** discover exact draft releases ([3f6898a](https://github.com/RocketsAreNostalgic/ran-booster/commit/3f6898a96258704d5bb0a1786ae6d2e98c16844c))
* **release:** discover exact draft releases ([#8](https://github.com/RocketsAreNostalgic/ran-booster/issues/8)) ([6e812bc](https://github.com/RocketsAreNostalgic/ran-booster/commit/6e812bc92a7088667779e6925583f4e2b11dd33a))
* **release:** fence stale native update offers ([b818457](https://github.com/RocketsAreNostalgic/ran-booster/commit/b818457da3301447408c94c3ba0248d227b7d4d0))
* **release:** gate publication on manifest changes ([#6](https://github.com/RocketsAreNostalgic/ran-booster/issues/6)) ([367647e](https://github.com/RocketsAreNostalgic/ran-booster/commit/367647ef7d4e01b2d985932bf4b9a157def40e83))
* **release:** preserve unrelated native update offers ([98f1b00](https://github.com/RocketsAreNostalgic/ran-booster/commit/98f1b007e33b5e35c617ba224ff55b22da32ac0f))
* **release:** read draft assets by id ([#64](https://github.com/RocketsAreNostalgic/ran-booster/issues/64)) ([ee90ad3](https://github.com/RocketsAreNostalgic/ran-booster/commit/ee90ad3d2498e208bff06c4e5bceed4ea166dfec))
* **release:** reconcile Release Please labels ([a6934f1](https://github.com/RocketsAreNostalgic/ran-booster/commit/a6934f16bb88c21be5d1b732df9590b7b67090c8))
* **release:** reconcile sparse action output ([16c48f2](https://github.com/RocketsAreNostalgic/ran-booster/commit/16c48f280381324025aa1a6806c1f7f69e974b9c))
* **release:** reconcile sparse action output ([d31512f](https://github.com/RocketsAreNostalgic/ran-booster/commit/d31512f270975c83eda85faec618e4cd585cf43f))
* **releases:** close candidate contract gaps ([687c5d4](https://github.com/RocketsAreNostalgic/ran-booster/commit/687c5d40d757a3c2d350cafe5fac3de5c8c69b55))
* **release:** sequence provider targets before updater selection ([89a9dc3](https://github.com/RocketsAreNostalgic/ran-booster/commit/89a9dc30a9ee94b7f8085b12e33ddfac8626b35c))
* **releases:** gate bundled administration ([357a64c](https://github.com/RocketsAreNostalgic/ran-booster/commit/357a64c373b63aca67c4c96d60acd5ad8f7dfb1c))
* **releases:** isolate candidate reader composition ([3c4f627](https://github.com/RocketsAreNostalgic/ran-booster/commit/3c4f6278e144cb3a693f51255102a3dc47cc0f31))
* **releases:** preserve candidate reader failure mapping ([27f8a83](https://github.com/RocketsAreNostalgic/ran-booster/commit/27f8a83f549ba60294cc0ef57d4da28ecd58a634))
* **releases:** preserve workflow continuity ([8b29069](https://github.com/RocketsAreNostalgic/ran-booster/commit/8b290693670dea7cf63f21a74a8944e68fa95700))
* **releases:** retain runtime candidate guard ([2b10f3d](https://github.com/RocketsAreNostalgic/ran-booster/commit/2b10f3da319dc516dbfed1246853627ed81eab19))
* **release:** use stable release API readback ([#7](https://github.com/RocketsAreNostalgic/ran-booster/issues/7)) ([adefb3e](https://github.com/RocketsAreNostalgic/ran-booster/commit/adefb3e2b776a15da7a55028f272f18381245042))
* **release:** verify exact updater beta.2 ([e59f9a6](https://github.com/RocketsAreNostalgic/ran-booster/commit/e59f9a63ad5817c6c40f6b55eaca9451c6f9078c))
* **release:** verify fallback archive source ([c5cd329](https://github.com/RocketsAreNostalgic/ran-booster/commit/c5cd329e748c670d632e5bc94648c2fc259bb7b4))
* **release:** verify fallback archive source ([6116489](https://github.com/RocketsAreNostalgic/ran-booster/commit/611648963cd2293c6280a77de4e75f8abcae6aae))
* remove primary tabs from package pages ([d19ec06](https://github.com/RocketsAreNostalgic/ran-booster/commit/d19ec064a19dc8e9adc837cf5a1b454ea713b5f4))
* reset credential storage without database key ([22abf81](https://github.com/RocketsAreNostalgic/ran-booster/commit/22abf816ee719d847db1e4b9e0a0b499e861df6a))
* separate canonical secret validation ([7ebcfdb](https://github.com/RocketsAreNostalgic/ran-booster/commit/7ebcfdb63e674e69c358c352e928804941126939))
* stabilize package settings styles ([8eec053](https://github.com/RocketsAreNostalgic/ran-booster/commit/8eec053c25175945384699bf6c26b5dbd5e5b7f0))
* support isolated shared-host secret storage ([5ecf435](https://github.com/RocketsAreNostalgic/ran-booster/commit/5ecf435879fd53d7ca809449ded5ba0502e82ca1))
* translate admin footer copyright ([9ce2cd9](https://github.com/RocketsAreNostalgic/ran-booster/commit/9ce2cd9599d3bf48a831b287610d56fa76377072))
* trim aggregate webhook actions ([980e7eb](https://github.com/RocketsAreNostalgic/ran-booster/commit/980e7ebf8649b016023f49cceac4729a640048a3))
* **webhook:** accept absent method override headers ([33bade2](https://github.com/RocketsAreNostalgic/ran-booster/commit/33bade2e0f06210839f64748c2a22e8025fb4a93))
* **webhooks:** clarify secret target entry ([7530c8b](https://github.com/RocketsAreNostalgic/ran-booster/commit/7530c8b1c0a0610db406eb798b880e9910ca9aeb))
* **webhooks:** keep unavailable history non-operational ([580a456](https://github.com/RocketsAreNostalgic/ran-booster/commit/580a456e7477e8013b726ae2150b98ebde830e40))
* **webhooks:** present recorded history safely ([c46c11b](https://github.com/RocketsAreNostalgic/ran-booster/commit/c46c11bf495fd552194981bad23227816ce6b603))
* **webhooks:** preserve uncertain mutation outcomes ([239317f](https://github.com/RocketsAreNostalgic/ran-booster/commit/239317fbb1780036b600d553fc9439d79f139e38))
* **webhooks:** retain history without management capability ([ff8103e](https://github.com/RocketsAreNostalgic/ran-booster/commit/ff8103e911d94789cccad83656f8c3718ffec594))
* **wordpress:** match exact update endpoints ([570df58](https://github.com/RocketsAreNostalgic/ran-booster/commit/570df58e2348d6f43be15e5cc562e16cdb1f6ebd))
* **wordpress:** reject malformed update endpoints ([5c0ae60](https://github.com/RocketsAreNostalgic/ran-booster/commit/5c0ae607ddc6f547fa545ceaec96ba6d35fac501))


### Miscellaneous Chores

* **release:** resume at beta.24 ([#77](https://github.com/RocketsAreNostalgic/ran-booster/issues/77)) ([642982f](https://github.com/RocketsAreNostalgic/ran-booster/commit/642982fd5d966ac46c2f5f51ae2e9d475683ed8b))
* start clean Core release lineage ([8932a4b](https://github.com/RocketsAreNostalgic/ran-booster/commit/8932a4bfae60cf7020c522cdc5afec63e215e9f9))

## [1.0.0-beta.23](https://github.com/RocketsAreNostalgic/ran-booster/compare/v1.0.0-beta.22...v1.0.0-beta.23) (2026-08-19)


### Features

* **admin:** adopt shared admin shell ([#66](https://github.com/RocketsAreNostalgic/ran-booster/issues/66)) ([766325a](https://github.com/RocketsAreNostalgic/ran-booster/commit/766325a06f37e84d3fcddf125662c12948e0b50d))


### Bug Fixes

* **release:** read draft assets by id ([#64](https://github.com/RocketsAreNostalgic/ran-booster/issues/64)) ([ee90ad3](https://github.com/RocketsAreNostalgic/ran-booster/commit/ee90ad3d2498e208bff06c4e5bceed4ea166dfec))

## [1.0.0-beta.22](https://github.com/RocketsAreNostalgic/ran-booster/compare/v1.0.0-beta.21...v1.0.0-beta.22) (2026-08-18)


### ⚠ BREAKING CHANGES

* activate bundled release administration
* **provider:** remove obsolete release discovery

### Features

* activate bundled release administration ([48bdf97](https://github.com/RocketsAreNostalgic/ran-booster/commit/48bdf97426d03f1c32176129b1818b6edfcf9192))
* **provider:** add exact release acquisition capability ([c02b8d0](https://github.com/RocketsAreNostalgic/ran-booster/commit/c02b8d089387292b290dca22e997d8ae8bcbac96))
* **provider:** add exact release acquisition capability ([76a25da](https://github.com/RocketsAreNostalgic/ran-booster/commit/76a25da837648f29234bf6d809948092325ea153))
* **provider:** add exact release inspection capability ([3f03df7](https://github.com/RocketsAreNostalgic/ran-booster/commit/3f03df7f2ab8f116e51543e2f4043edd8109bef7))
* **provider:** add exact release inspection capability ([8f36461](https://github.com/RocketsAreNostalgic/ran-booster/commit/8f36461925af333e6e05a3997f1d9c9f697e2269))
* **provider:** move native release targets behind providers ([3331117](https://github.com/RocketsAreNostalgic/ran-booster/commit/3331117dc529ec5a2732b6a52b0e80a20d10c075))
* **provider:** move native release targets behind providers ([1f8ffb8](https://github.com/RocketsAreNostalgic/ran-booster/commit/1f8ffb82224a427777797968c4477cc33698f4ce))
* **provider:** place webhook management by capability ([5bc23a1](https://github.com/RocketsAreNostalgic/ran-booster/commit/5bc23a1d811fb86247b0d0af14e043a09e58d0ab))
* **provider:** remove obsolete release discovery ([1705852](https://github.com/RocketsAreNostalgic/ran-booster/commit/1705852b1de457ee67a1da9ab85978da31d4bdfe))
* **provider:** route managed release preflight through providers ([1f2c3bc](https://github.com/RocketsAreNostalgic/ran-booster/commit/1f2c3bcdbaf4fefae277f81405e15574e2c327f6))
* **provider:** route managed release preflight through providers ([53e5c21](https://github.com/RocketsAreNostalgic/ran-booster/commit/53e5c21c9e84aa40b7f586f64fd92c7c855dfabb))
* **releases:** bundle release administration successor ([44f6b78](https://github.com/RocketsAreNostalgic/ran-booster/commit/44f6b781acba080363ad165a3704c9191781adf3))
* **releases:** bundle release administration successor ([885a0da](https://github.com/RocketsAreNostalgic/ran-booster/commit/885a0da95b64661968aba89d09a7ebe052e2a60e))


### Bug Fixes

* **admin:** align public extension product copy ([57d00ec](https://github.com/RocketsAreNostalgic/ran-booster/commit/57d00ec58dab626dafa89279ebb3f758263a6736))
* **admin:** align release lifecycle and public extension surfaces ([443e093](https://github.com/RocketsAreNostalgic/ran-booster/commit/443e093abed3548b7d3175c36d7c789b1c044d4c))
* **ci:** authenticate candidate fetches ([#60](https://github.com/RocketsAreNostalgic/ran-booster/issues/60)) ([24fd6b7](https://github.com/RocketsAreNostalgic/ran-booster/commit/24fd6b756aa5cfe06d58eaa0cb90982f41f4a11b))
* **ci:** verify candidate run events exactly ([#62](https://github.com/RocketsAreNostalgic/ran-booster/issues/62)) ([2821a76](https://github.com/RocketsAreNostalgic/ran-booster/commit/2821a76b9195c92851db09710af2be6e83185f9a))
* **provider:** complete webhook management admission ([725d15c](https://github.com/RocketsAreNostalgic/ran-booster/commit/725d15ca4819d1e9b6dd22d227c5f5ef36e07683))
* **provider:** fail closed on missing artifact cleanup ([236e4ac](https://github.com/RocketsAreNostalgic/ran-booster/commit/236e4ac1d72efdc44e425d36a96fb260384b348c))
* **provider:** preserve release inspection semantics ([eb5c1bf](https://github.com/RocketsAreNostalgic/ran-booster/commit/eb5c1bff68b4808cead97a2ba9bec7abca6ca891))
* **release:** authenticate candidate fetches ([cce26f9](https://github.com/RocketsAreNostalgic/ran-booster/commit/cce26f9e10e15e484e2548da452a9fe0be2dfcff))
* **release:** authenticate candidate fetches ([a08a14a](https://github.com/RocketsAreNostalgic/ran-booster/commit/a08a14ada2e0bc60d3e15aedd8705fa417fe5bbb))
* **release:** defer external native enablement ([2d4be8d](https://github.com/RocketsAreNostalgic/ran-booster/commit/2d4be8dc7144b74b577d704eea3bf94ab352c991))
* **release:** preserve unrelated native update offers ([98f1b00](https://github.com/RocketsAreNostalgic/ran-booster/commit/98f1b007e33b5e35c617ba224ff55b22da32ac0f))
* **release:** reconcile sparse action output ([16c48f2](https://github.com/RocketsAreNostalgic/ran-booster/commit/16c48f280381324025aa1a6806c1f7f69e974b9c))
* **release:** reconcile sparse action output ([d31512f](https://github.com/RocketsAreNostalgic/ran-booster/commit/d31512f270975c83eda85faec618e4cd585cf43f))
* **releases:** gate bundled administration ([357a64c](https://github.com/RocketsAreNostalgic/ran-booster/commit/357a64c373b63aca67c4c96d60acd5ad8f7dfb1c))
* **releases:** preserve workflow continuity ([8b29069](https://github.com/RocketsAreNostalgic/ran-booster/commit/8b290693670dea7cf63f21a74a8944e68fa95700))

## [1.0.0-beta.21](https://github.com/RocketsAreNostalgic/ran-booster/compare/v1.0.0-beta.20...v1.0.0-beta.21) (2026-08-17)


### Features

* **provider:** add release candidate listing capability ([38c4620](https://github.com/RocketsAreNostalgic/ran-booster/commit/38c4620bfc868b98d45a48b23bbefc2bdc533884))
* **provider:** add release candidate listing capability ([d84a7a7](https://github.com/RocketsAreNostalgic/ran-booster/commit/d84a7a756e37b16a42ad4ac2ebeeb49e3e3469e5))


### Bug Fixes

* **provider:** contain partial release capabilities ([d94d0be](https://github.com/RocketsAreNostalgic/ran-booster/commit/d94d0be65b22a7cbe41c452bdf221c8e0fd33f08))

## [1.0.0-beta.20](https://github.com/RocketsAreNostalgic/ran-booster/compare/v1.0.0-beta.19...v1.0.0-beta.20) (2026-08-17)


### Features

* **provider:** add release metadata capability ([9c545e1](https://github.com/RocketsAreNostalgic/ran-booster/commit/9c545e1904189daf634c71368df2e07bef1bfb4a))
* **provider:** add release metadata capability ([20c0b6a](https://github.com/RocketsAreNostalgic/ran-booster/commit/20c0b6abd37f3bcc673a4f5718810b2cfe9d8fb4))


### Bug Fixes

* **provider:** normalize metadata predicate contract ([2875ee2](https://github.com/RocketsAreNostalgic/ran-booster/commit/2875ee2634b834e6451e31ecd328eaed2d5fb148))

## [1.0.0-beta.19](https://github.com/RocketsAreNostalgic/ran-booster/compare/v1.0.0-beta.18...v1.0.0-beta.19) (2026-08-17)


### ⚠ BREAKING CHANGES

* **provider:** require exact capability facets

### Features

* **provider:** require exact capability facets ([5c10d23](https://github.com/RocketsAreNostalgic/ran-booster/commit/5c10d2356aba227b79f52c03e3ad32e5ac6cce2c))


### Bug Fixes

* **release:** fence stale native update offers ([b818457](https://github.com/RocketsAreNostalgic/ran-booster/commit/b818457da3301447408c94c3ba0248d227b7d4d0))
* **release:** sequence provider targets before updater selection ([89a9dc3](https://github.com/RocketsAreNostalgic/ran-booster/commit/89a9dc30a9ee94b7f8085b12e33ddfac8626b35c))

## [1.0.0-beta.18](https://github.com/RocketsAreNostalgic/ran-booster/compare/v1.0.0-beta.17...v1.0.0-beta.18) (2026-08-15)


### ⚠ BREAKING CHANGES

* bundle GitHub webhook management in Core ([#34](https://github.com/RocketsAreNostalgic/ran-booster/issues/34))

### Features

* bundle GitHub webhook management in Core ([#34](https://github.com/RocketsAreNostalgic/ran-booster/issues/34)) ([8ae76b8](https://github.com/RocketsAreNostalgic/ran-booster/commit/8ae76b88b3444a20d7e9be2e3440e109b98c4831))

## [1.0.0-beta.17](https://github.com/RocketsAreNostalgic/ran-booster/compare/v1.0.0-beta.16...v1.0.0-beta.17) (2026-08-14)


### Bug Fixes

* release rejected-admission ledger removal ([567c5c1](https://github.com/RocketsAreNostalgic/ran-booster/commit/567c5c1062309c496a2da0cb5f9bd46285705b83))
* release rejected-admission ledger removal ([a96437a](https://github.com/RocketsAreNostalgic/ran-booster/commit/a96437adda2fa3d678ef817acef96146ce39496d))

## [1.0.0-beta.16](https://github.com/RocketsAreNostalgic/ran-booster/compare/v1.0.0-beta.15...v1.0.0-beta.16) (2026-08-13)


### Features

* publish provider API 9 ([89d5817](https://github.com/RocketsAreNostalgic/ran-booster/commit/89d5817f9331bfe9d7ca19439f8024b0b09affda))
* publish Provider API 9 ([70ce8bc](https://github.com/RocketsAreNostalgic/ran-booster/commit/70ce8bc6453e53e33634aa3f7a6f863894d1273e))

## [1.0.0-beta.15](https://github.com/RocketsAreNostalgic/ran-booster/compare/v1.0.0-beta.14...v1.0.0-beta.15) (2026-08-11)


### Features

* complete V3 Core and adopt updater beta.5 ([#25](https://github.com/RocketsAreNostalgic/ran-booster/issues/25)) ([e52e42b](https://github.com/RocketsAreNostalgic/ran-booster/commit/e52e42b02ae48b868766d717ac343f10cb951700))


### Bug Fixes

* clarify guidance on using local sibling as standards authority ([a993cf2](https://github.com/RocketsAreNostalgic/ran-booster/commit/a993cf2c29cdc6d17aaafe67cffa93aa8b317c2f))
* **deps:** adopt updater beta.5 ([7b01f4d](https://github.com/RocketsAreNostalgic/ran-booster/commit/7b01f4d743c095a08a3f7966bf33d01000203315))

## [1.0.0-beta.14](https://github.com/RocketsAreNostalgic/ran-booster/compare/v1.0.0-beta.13...v1.0.0-beta.14) (2026-08-09)


### Bug Fixes

* accept exact WordPress 7.0 floor ([9b3bd48](https://github.com/RocketsAreNostalgic/ran-booster/commit/9b3bd4866ca85eb00fb750db7a4fef929a81b28c))
* import Blueprint credentials for managed packages ([62fb097](https://github.com/RocketsAreNostalgic/ran-booster/commit/62fb097f62e44e92033594b10cb0a046eeafcf5a))
* reset credential storage without database key ([22abf81](https://github.com/RocketsAreNostalgic/ran-booster/commit/22abf816ee719d847db1e4b9e0a0b499e861df6a))
* stabilize package settings styles ([8eec053](https://github.com/RocketsAreNostalgic/ran-booster/commit/8eec053c25175945384699bf6c26b5dbd5e5b7f0))

## [1.0.0-beta.13](https://github.com/RocketsAreNostalgic/ran-booster/compare/v1.0.0-beta.12...v1.0.0-beta.13) (2026-08-09)


### Bug Fixes

* recover orphaned credential storage safely ([02ab40b](https://github.com/RocketsAreNostalgic/ran-booster/commit/02ab40b4f240372b48ee4535b644e808e09f9940))

## [1.0.0-beta.12](https://github.com/RocketsAreNostalgic/ran-booster/compare/v1.0.0-beta.11...v1.0.0-beta.12) (2026-08-09)


### Bug Fixes

* support isolated shared-host secret storage ([5ecf435](https://github.com/RocketsAreNostalgic/ran-booster/commit/5ecf435879fd53d7ca809449ded5ba0502e82ca1))

## [1.0.0-beta.11](https://github.com/RocketsAreNostalgic/ran-booster/compare/v1.0.0-beta.10...v1.0.0-beta.11) (2026-08-09)


### Features

* recover migrated credential storage ([e19ea80](https://github.com/RocketsAreNostalgic/ran-booster/commit/e19ea80affd3a18095ade0ff799e76134076026e))


### Bug Fixes

* align automatic secrets directory setup ([4e55bf9](https://github.com/RocketsAreNostalgic/ran-booster/commit/4e55bf9fdd964861def033bbb549a517f6295c38))
* **credentials:** make secret validation actionable ([cc21753](https://github.com/RocketsAreNostalgic/ran-booster/commit/cc21753e3cdabfc0bd29253f657e1a945b8bb315))
* **webhooks:** clarify secret target entry ([7530c8b](https://github.com/RocketsAreNostalgic/ran-booster/commit/7530c8b1c0a0610db406eb798b880e9910ca9aeb))

## [1.0.0-beta.10](https://github.com/RocketsAreNostalgic/ran-booster/compare/v1.0.0-beta.9...v1.0.0-beta.10) (2026-08-08)


### Features

* add explicit transporter credential export ([8a43d3f](https://github.com/RocketsAreNostalgic/ran-booster/commit/8a43d3f9e2f5392a620f8e475260030805ab9e47))
* add explicit transporter credential import ([1c217e2](https://github.com/RocketsAreNostalgic/ran-booster/commit/1c217e25b066e0cc564e692a326cb643aab16650))
* polish transporter credential review ([5bfd553](https://github.com/RocketsAreNostalgic/ran-booster/commit/5bfd5534c848e86e037d352d7f3fddff7e9b8562))
* **portability:** close explicit credential transfer controls ([2027b7c](https://github.com/RocketsAreNostalgic/ran-booster/commit/2027b7ca35b1be67f04bf1c2b8164e5b9811bdf5))


### Bug Fixes

* **credentials:** make credential entry actionable ([f51db4a](https://github.com/RocketsAreNostalgic/ran-booster/commit/f51db4a1a9302b59c5c38cac490d5e2c950cb1c3))
* **dependencies:** add libc support for various resolver bindings in pnpm-lock.yaml ([6ea2644](https://github.com/RocketsAreNostalgic/ran-booster/commit/6ea2644d1f458add4b49828b0e7cd629e19ea593))
* **deployment:** harden archive preflight retries ([ea37584](https://github.com/RocketsAreNostalgic/ran-booster/commit/ea375847f44c2c677002de51898dea06a08905c4))
* **interaction:** enhance response handling for provider profile save operations ([e657d35](https://github.com/RocketsAreNostalgic/ran-booster/commit/e657d359ab82ed43e8ebb2f8ee8b6eccbe549c05))
* **webhooks:** preserve uncertain mutation outcomes ([239317f](https://github.com/RocketsAreNostalgic/ran-booster/commit/239317fbb1780036b600d553fc9439d79f139e38))

## [1.0.0-beta.9](https://github.com/RocketsAreNostalgic/ran-booster/compare/v1.0.0-beta.8...v1.0.0-beta.9) (2026-08-04)


### Bug Fixes

* contain package source card text ([331b4ba](https://github.com/RocketsAreNostalgic/ran-booster/commit/331b4ba7bf9f215c383e66a11ef1a0667e73af0c))
* expose release validation diagnostics ([fcd1951](https://github.com/RocketsAreNostalgic/ran-booster/commit/fcd19517cc3ad8c151c42afd80d4fef283ec0689))
* identify Transporter package results ([4b4ea5d](https://github.com/RocketsAreNostalgic/ran-booster/commit/4b4ea5ddfc287b465a4503206088718c2dfd3135))

## [1.0.0-beta.8](https://github.com/RocketsAreNostalgic/ran-booster/compare/v1.0.0-beta.7...v1.0.0-beta.8) (2026-08-04)


### Bug Fixes

* make secrets storage failures actionable ([912b85c](https://github.com/RocketsAreNostalgic/ran-booster/commit/912b85cef47099c55531d9be795160ab01c8931f))

## [1.0.0-beta.7](https://github.com/RocketsAreNostalgic/ran-booster/compare/v1.0.0-beta.6...v1.0.0-beta.7) (2026-08-04)


### Bug Fixes

* expose secrets storage failures ([e50308e](https://github.com/RocketsAreNostalgic/ran-booster/commit/e50308e46a00dcfa50880f7549e0ec92fc9decd9))
* **release:** allow false label state ([1015315](https://github.com/RocketsAreNostalgic/ran-booster/commit/10153151b208aac2341e2a826278643bfcfd0967))
* **release:** allow PR label mutation ([0cd2b93](https://github.com/RocketsAreNostalgic/ran-booster/commit/0cd2b937ac9ce8e337d09077deee4fe9ee89016b))
* **release:** reconcile Release Please labels ([a6934f1](https://github.com/RocketsAreNostalgic/ran-booster/commit/a6934f16bb88c21be5d1b732df9590b7b67090c8))

## [1.0.0-beta.6](https://github.com/RocketsAreNostalgic/ran-booster/compare/v1.0.0-beta.5...v1.0.0-beta.6) (2026-08-04)


### Bug Fixes

* make secrets storage actionable ([c9d0f26](https://github.com/RocketsAreNostalgic/ran-booster/commit/c9d0f26991073c7750dbb942635243f7d9feb35e))
* pass Core repository identity to updater ([a7763f4](https://github.com/RocketsAreNostalgic/ran-booster/commit/a7763f40cc03b6f97022653ec5e8ae513860c8bb))

## [1.0.0-beta.5](https://github.com/RocketsAreNostalgic/ran-booster/compare/v1.0.0-beta.4...v1.0.0-beta.5) (2026-08-04)


### Bug Fixes

* **deps:** adopt updater beta.4 finalization fix ([3544475](https://github.com/RocketsAreNostalgic/ran-booster/commit/35444754bd24097b9ed40925a32dbf7ca2859260))
* **deps:** adopt updater beta.4 finalization fix ([3a62017](https://github.com/RocketsAreNostalgic/ran-booster/commit/3a620174b070354d2c44baec15e4c87dee55b777))

## [1.0.0-beta.4](https://github.com/RocketsAreNostalgic/ran-booster/compare/v1.0.0-beta.3...v1.0.0-beta.4) (2026-08-03)


### Bug Fixes

* **deps:** adopt updater beta.3 continuity fix ([3a493e8](https://github.com/RocketsAreNostalgic/ran-booster/commit/3a493e89efa5c3795245a732af92419343f03cec))
* **deps:** adopt updater beta.3 continuity fix ([4d7bb9e](https://github.com/RocketsAreNostalgic/ran-booster/commit/4d7bb9e3ee904b52591eac279818570e8571b777))

## [1.0.0-beta.3](https://github.com/RocketsAreNostalgic/ran-booster/compare/v1.0.0-beta.2...v1.0.0-beta.3) (2026-08-03)


### ⚠ BREAKING CHANGES

* adopt updater-owned native artifact claims

### Bug Fixes

* adopt updater-owned native artifact claims ([4996efb](https://github.com/RocketsAreNostalgic/ran-booster/commit/4996efb8ba10979232fa9e5252156dda504b5c02))
* **release:** discover exact draft releases ([3f6898a](https://github.com/RocketsAreNostalgic/ran-booster/commit/3f6898a96258704d5bb0a1786ae6d2e98c16844c))
* **release:** discover exact draft releases ([#8](https://github.com/RocketsAreNostalgic/ran-booster/issues/8)) ([6e812bc](https://github.com/RocketsAreNostalgic/ran-booster/commit/6e812bc92a7088667779e6925583f4e2b11dd33a))
* **release:** use stable release API readback ([#7](https://github.com/RocketsAreNostalgic/ran-booster/issues/7)) ([adefb3e](https://github.com/RocketsAreNostalgic/ran-booster/commit/adefb3e2b776a15da7a55028f272f18381245042))
* **release:** verify exact updater beta.2 ([e59f9a6](https://github.com/RocketsAreNostalgic/ran-booster/commit/e59f9a63ad5817c6c40f6b55eaca9451c6f9078c))

## [1.0.0-beta.2](https://github.com/RocketsAreNostalgic/ran-booster/compare/v0.1.0-beta.2...v1.0.0-beta.2) (2026-08-03)


### ⚠ BREAKING CHANGES

* adopt shared updater authority contract ([#4](https://github.com/RocketsAreNostalgic/ran-booster/issues/4))

### Features

* adopt shared updater authority contract ([#4](https://github.com/RocketsAreNostalgic/ran-booster/issues/4)) ([16198f7](https://github.com/RocketsAreNostalgic/ran-booster/commit/16198f78b9a860d97a3ca5c26c670b76e5956554))


### Bug Fixes

* **release:** gate publication on manifest changes ([#6](https://github.com/RocketsAreNostalgic/ran-booster/issues/6)) ([367647e](https://github.com/RocketsAreNostalgic/ran-booster/commit/367647ef7d4e01b2d985932bf4b9a157def40e83))

## [0.1.0-beta.2](https://github.com/RocketsAreNostalgic/ran-booster/compare/v0.1.0-beta.1...v0.1.0-beta.2) (2026-08-02)


### Features

* add repository webhook detail routes ([6318077](https://github.com/RocketsAreNostalgic/ran-booster/commit/63180776fadb8da073678abe9b05230ba2c73b60))
* **admin:** collapse package danger zone ([d44b43a](https://github.com/RocketsAreNostalgic/ran-booster/commit/d44b43abb8db3b143aad1e58d12ddbe157c6d5ca))


### Bug Fixes

* align managed package list controls ([1fc484f](https://github.com/RocketsAreNostalgic/ran-booster/commit/1fc484ff1e9f78d9a8c3bfb776c2133135860c21))
* keep development notice styling consistent ([30e1ba7](https://github.com/RocketsAreNostalgic/ran-booster/commit/30e1ba7e8a2913a242c4952f0cf3c03db647d857))
* open advanced settings from webhook rows ([d36ae82](https://github.com/RocketsAreNostalgic/ran-booster/commit/d36ae82d9637cf59bbe172903a59389857e9ccf7))
* remove primary tabs from package pages ([d19ec06](https://github.com/RocketsAreNostalgic/ran-booster/commit/d19ec064a19dc8e9adc837cf5a1b454ea713b5f4))
* trim aggregate webhook actions ([980e7eb](https://github.com/RocketsAreNostalgic/ran-booster/commit/980e7ebf8649b016023f49cceac4729a640048a3))

## 0.1.0-beta.1 (2026-08-02)


### Miscellaneous Chores

* Start clean Core release lineage ([8932a4b](https://github.com/RocketsAreNostalgic/ran-booster/commit/8932a4bfae60cf7020c522cdc5afec63e215e9f9)).

All notable changes to this project will be documented in this file.

The repository history was reset before the `0.1.0-beta.1` release to remove
private development material from the clean source lineage. Earlier release
notes are retained only in the owner's offline recovery archive.
