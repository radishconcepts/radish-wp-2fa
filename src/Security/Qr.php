<?php

declare( strict_types=1 );

namespace RadishConcepts\TwoFactor\Security;

use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

final class Qr {

	private const SIZE = 240;

	public static function svg( string $content ): string {
		$renderer = new ImageRenderer(
			new RendererStyle( self::SIZE, 1 ),
			new SvgImageBackEnd()
		);

		$svg = ( new Writer( $renderer ) )->writeString( $content );

		return preg_replace( '/^<\?xml[^>]*\?>\s*/', '', $svg ) ?? $svg;
	}
}
