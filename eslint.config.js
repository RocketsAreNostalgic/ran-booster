import ranWordPress from "@rocketsarenostalgic/quality-config/eslint/wordpress";

export default [
	{
		ignores: ["assets/lib/**"],
	},
	...ranWordPress,
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
