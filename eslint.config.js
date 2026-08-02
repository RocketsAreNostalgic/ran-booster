import wordpress from "@wordpress/eslint-plugin";

export default [
	{
		ignores: ["assets/lib/**"],
	},
	...wordpress.configs.recommended,
	{
		files: [ "assets/**/*.js" ],
		languageOptions: {
			globals: {
				$: "readonly",
				jQuery: "readonly",
			},
		},
		settings: {
			react: {
				version: "999.999.999",
			},
		},
	},
];
