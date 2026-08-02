<?php

declare(strict_types=1);

namespace RAN\Admin;

use InvalidArgumentException;

/**
 * Immutable plugin/theme labels and routing used by the shared package views.
 */
final class PackageViewConfig {

	private function __construct(
		private readonly string $type,
		private readonly string $singularLabel,
		private readonly string $pluralLabel,
		private readonly string $identifierField,
		private readonly string $pageSlug,
		private readonly string $subdirectoryExample,
		private readonly string $nestedSubdirectoryExample
	) {
	}

	public static function plugin(): self {
		return new self(
			'plugin',
			'Plugin',
			'Plugins',
			'file',
			'ran-booster-plugins',
			'awesome-plugin',
			'plugins/awesome-plugin'
		);
	}

	public static function theme(): self {
		return new self(
			'theme',
			'Theme',
			'Themes',
			'stylesheet',
			'ran-booster-themes',
			'awesome-theme',
			'themes/awesome-theme'
		);
	}

	public function getType(): string {
		return $this->type;
	}

	public function getSingularLabel(): string {
		return $this->singularLabel;
	}

	public function getPluralLabel(): string {
		return $this->pluralLabel;
	}

	public function getIdentifierField(): string {
		return $this->identifierField;
	}

	public function getPageSlug(): string {
		return $this->pageSlug;
	}

	public function getCreatePageSlug(): string {
		return $this->pageSlug . '-create';
	}

	public function getSubdirectoryExample(): string {
		return $this->subdirectoryExample;
	}

	public function getNestedSubdirectoryExample(): string {
		return $this->nestedSubdirectoryExample;
	}

	public function getAction( string $operation ): string {
		if ( ! in_array( $operation, array( 'install', 'edit', 'update', 'unlink', 'unlink-delete', 'bulk' ), true ) ) {
			throw new InvalidArgumentException( 'Unsupported package action.' );
		}

		return $operation . '-' . $this->type;
	}
}
