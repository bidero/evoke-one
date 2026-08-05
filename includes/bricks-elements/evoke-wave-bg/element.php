<?php
defined( 'ABSPATH' ) || exit;

class Evk_Wave_Bg_Element extends \Bricks\Element {

	public $category = EVK_BRICKS_CATEGORY;
	public $name     = 'evk-wave-bg';
	public $icon     = 'ti-brush-alt';
	public $tag      = 'div';
	public $nestable = false;

	// Etykieta musi się zgadzać z evk_elements_registry()['wave_bg']['label'].
	public function get_label() {
		return 'Wave Background';
	}

	public function get_keywords() {
		return [ 'evoke', 'wave', 'background', 'tło', 'gradient', 'three', 'webgl' ];
	}

	public function set_controls() {

		// ── POZYCJONOWANIE ─────────────────────────────────────────────────────

		$this->controls['sep_position'] = [
			'tab'   => 'content',
			'type'  => 'separator',
			'label' => 'Pozycjonowanie',
		];

		$this->controls['position'] = [
			'tab'     => 'content',
			'label'   => 'Pozycja',
			'type'    => 'select',
			'options' => [ 'absolute' => 'absolute', 'fixed' => 'fixed', 'relative' => 'relative', 'static' => 'static' ],
			'default' => 'absolute',
		];

		$this->controls['z_index'] = [
			'tab'     => 'content',
			'label'   => 'Z-indeks',
			'type'    => 'number',
			'min'     => -100, 'max' => 9999, 'step' => 1,
			'default' => 0,
		];

		$this->controls['top']        = [ 'tab' => 'content', 'label' => 'Góra',       'type' => 'text', 'default' => '0'    ];
		$this->controls['left']       = [ 'tab' => 'content', 'label' => 'Lewa',       'type' => 'text', 'default' => '0'    ];
		$this->controls['width']      = [ 'tab' => 'content', 'label' => 'Szerokość',  'type' => 'text', 'default' => '100%' ];
		$this->controls['height']     = [ 'tab' => 'content', 'label' => 'Wysokość',   'type' => 'text', 'default' => '100%' ];
		$this->controls['min_height'] = [ 'tab' => 'content', 'label' => 'Min. wys.',  'type' => 'text', 'default' => '100vh', 'description' => 'Bez tego canvas może mieć 0px.' ];

		$this->controls['pointer_events'] = [
			'tab'     => 'content',
			'label'   => 'Zdarzenia wskaźnika',
			'type'    => 'select',
			'options' => [ 'none' => 'none', 'auto' => 'auto' ],
			'default' => 'none',
		];

		// ── WARIANT ────────────────────────────────────────────────────────────

		$this->controls['sep_variant'] = [
			'tab'   => 'content',
			'type'  => 'separator',
			'label' => 'Wariant',
		];

		$this->controls['variation'] = [
			'tab'     => 'content',
			'label'   => 'Wariant',
			'type'    => 'select',
			'options' => [
				'v0'     => '0 — rot 0.7 rad, prawa strona',
				'v1'     => '1 — rot 2.7 rad, wąski',
				'v2'     => '2 — rot 2.5 rad',
				'v3'     => '3 — rot 3.5 rad, lewa strona',
				'v4'     => '4 — rot 1.1 rad',
				'v5'     => '5 — rot 4.7 rad, wąski',
				'custom' => '✏ Własny...',
			],
			'default' => 'v0',
		];

		// ── WŁASNY WARIANT ─────────────────────────────────────────────────────

		$this->controls['sep_custom'] = [
			'tab'      => 'content',
			'type'     => 'separator',
			'label'    => 'Własny wariant',
			'required' => [ 'variation', '=', 'custom' ],
		];

		$custom_fields = [
			'custom_width_multiplier'  => [ 'Szerokość siatki',       0.1, 2.0,  0.05,  0.6   ],
			'custom_height_multiplier' => [ 'Wysokość siatki',        0.1, 4.0,  0.05,  2.0   ],
			'custom_position_x'        => [ 'Pozycja kamery X',      -1.0, 1.0,  0.05,  0.2   ],
			'custom_position_y'        => [ 'Pozycja kamery Y',      -1.0, 1.0,  0.05,  0.0   ],
			'custom_rotation'          => [ 'Rotacja (rad)',          -7.0, 7.0,  0.05,  3.0   ],
			'custom_shape_amount'      => [ 'Shape amount',           0.0,  1.0,  0.01,  0.25  ],
			'custom_distortion_amount' => [ 'Distortion amount',      0.0,  1.0,  0.01,  0.15  ],
			'custom_speed_multiplier'  => [ 'Prędkość',               0.0,  5.0,  0.1,   1.0   ],
			'custom_time_start'        => [ 'Time start',          -200.0, 200.0, 10.0, -100.0 ],
		];

		foreach ( $custom_fields as $key => [ $label, $min, $max, $step, $default ] ) {
			$this->controls[ $key ] = [
				'tab'      => 'content',
				'label'    => $label,
				'type'     => 'number',
				'min'      => $min, 'max' => $max, 'step' => $step,
				'default'  => $default,
				'required' => [ 'variation', '=', 'custom' ],
			];
		}

		// ── KOLORY ─────────────────────────────────────────────────────────────

		$this->controls['sep_colors'] = [
			'tab'   => 'content',
			'type'  => 'separator',
			'label' => 'Kolory gradientu',
		];

		$color_defaults = [ '#F2E6DB', '#71D9E9', '#8c3dd0', '#D03F83', '#F43FF9', '#8c3dd0' ];
		for ( $i = 1; $i <= 6; $i++ ) {
			$this->controls[ 'color_' . $i ] = [
				'tab'     => 'content',
				'label'   => 'Kolor ' . $i,
				'type'    => 'color',
				'default' => [ 'hex' => $color_defaults[ $i - 1 ] ],
			];
		}

		// ── EFEKT MYSZY ────────────────────────────────────────────────────────

		$this->controls['sep_mouse'] = [
			'tab'   => 'content',
			'type'  => 'separator',
			'label' => 'Efekt myszy',
		];

		$this->controls['mouse_effect'] = [
			'tab'     => 'content',
			'label'   => 'Siła efektu myszy',
			'type'    => 'number',
			'min'     => 0, 'max' => 3, 'step' => 0.1,
			'default' => 1.0,
		];

		// ── SZUM (GRAIN) ───────────────────────────────────────────────────────

		$this->controls['sep_noise'] = [
			'tab'   => 'content',
			'type'  => 'separator',
			'label' => 'Szum (grain)',
		];

		$this->controls['noise_enabled'] = [
			'tab'     => 'content',
			'label'   => 'Włącz szum',
			'type'    => 'checkbox',
			'default' => true,
		];

		$this->controls['noise_intensity'] = [
			'tab'      => 'content',
			'label'    => 'Intensywność szumu',
			'type'     => 'number',
			'min'      => 0, 'max' => 1, 'step' => 0.01,
			'default'  => 0.08,
			'required' => [ 'noise_enabled', '=', true ],
		];

		// ── MASKA DOLNA ────────────────────────────────────────────────────────

		$this->controls['sep_mask'] = [
			'tab'   => 'content',
			'type'  => 'separator',
			'label' => 'Maska (fade-out dół)',
		];

		$this->controls['mask_enabled'] = [
			'tab'     => 'content',
			'label'   => 'Włącz maskę dolną',
			'type'    => 'checkbox',
			'default' => true,
		];

		$this->controls['mask_start'] = [
			'tab'      => 'content',
			'label'    => 'Start zanikania (%)',
			'type'     => 'number',
			'min'      => 0, 'max' => 100, 'step' => 1,
			'default'  => 90,
			'required' => [ 'mask_enabled', '=', true ],
		];

		// ── MASKA GÓRNA ────────────────────────────────────────────────────────

		$this->controls['sep_mask_top'] = [
			'tab'   => 'content',
			'type'  => 'separator',
			'label' => 'Maska (fade-out góra)',
		];

		$this->controls['mask_top_enabled'] = [
			'tab'     => 'content',
			'label'   => 'Włącz maskę górną',
			'type'    => 'checkbox',
			'default' => false,
		];

		$this->controls['mask_top_end'] = [
			'tab'      => 'content',
			'label'    => 'Koniec zanikania (%)',
			'type'     => 'number',
			'min'      => 0, 'max' => 100, 'step' => 1,
			'default'  => 10,
			'required' => [ 'mask_top_enabled', '=', true ],
		];

		// ── SCROLL: OPACITY FADE ───────────────────────────────────────────────

		$this->controls['sep_scroll_fade'] = [
			'tab'   => 'content',
			'type'  => 'separator',
			'label' => 'Scroll — zanikanie (opacity)',
		];

		$this->controls['scroll_fade_enabled'] = [
			'tab'     => 'content',
			'label'   => 'Zanikaj po scrollu',
			'type'    => 'checkbox',
			'default' => false,
		];

		$this->controls['scroll_fade_threshold'] = [
			'tab'      => 'content',
			'label'    => 'Próg zanikania (px)',
			'type'     => 'number',
			'min'      => 0, 'max' => 10000, 'step' => 10,
			'default'  => 600,
			'required' => [ 'scroll_fade_enabled', '=', true ],
		];

		$this->controls['scroll_fade_duration'] = [
			'tab'      => 'content',
			'label'    => 'Czas zanikania (ms)',
			'type'     => 'number',
			'min'      => 0, 'max' => 3000, 'step' => 50,
			'default'  => 500,
			'required' => [ 'scroll_fade_enabled', '=', true ],
		];

		$this->controls['scroll_fade_opacity'] = [
			'tab'      => 'content',
			'label'    => 'Docelowe opacity (0–1)',
			'type'     => 'number',
			'min'      => 0, 'max' => 1, 'step' => 0.05,
			'default'  => 0,
			'required' => [ 'scroll_fade_enabled', '=', true ],
		];

		// ── SCROLL: PAUZA CPU/GPU ──────────────────────────────────────────────

		$this->controls['sep_scroll_pause'] = [
			'tab'   => 'content',
			'type'  => 'separator',
			'label' => 'Scroll — pauza CPU/GPU',
		];

		$this->controls['scroll_pause_enabled'] = [
			'tab'     => 'content',
			'label'   => 'Pauzuj render po scrollu',
			'type'    => 'checkbox',
			'default' => false,
		];

		$this->controls['scroll_pause_threshold'] = [
			'tab'      => 'content',
			'label'    => 'Próg pauzy (px)',
			'type'     => 'number',
			'min'      => 0, 'max' => 10000, 'step' => 10,
			'default'  => 600,
			'required' => [ 'scroll_pause_enabled', '=', true ],
		];
	}

	private function color_hex( $val, string $fallback ): string {
		if ( empty( $val ) ) return $fallback;

		if ( is_array( $val ) ) {
			// 1. hex jest idealny dla THREE.js
			if ( ! empty( $val['hex'] ) ) return $val['hex'];

			// 2. Bricks może zapisać rgba() w 'rgb' lub 'raw' (np. globalne kolory, kolory z alpha)
			$raw = $val['rgb'] ?? $val['raw'] ?? '';
			if ( $raw ) {
				// rgba(r, g, b, a) → #rrggbb (THREE.js nie obsługuje rgba)
				if ( preg_match( '/rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/i', $raw, $m ) ) {
					return sprintf( '#%02x%02x%02x', (int) $m[1], (int) $m[2], (int) $m[3] );
				}
				return $raw; // np. var(--kolor) — THREE.Color spróbuje sparsować
			}

			return $fallback;
		}

		return (string) $val ?: $fallback;
	}

	public function render() {
		$s = $this->settings;

		$position       = $s['position']       ?? 'absolute';
		$z_index        = isset( $s['z_index'] ) ? (int) $s['z_index'] : 0;
		$top            = $s['top']            ?? '0';
		$left           = $s['left']           ?? '0';
		$width          = $s['width']          ?? '100%';
		$height         = $s['height']         ?? '100%';
		$min_height     = $s['min_height']     ?? '100vh';
		$pointer_events = $s['pointer_events'] ?? 'none';

		$mask_enabled     = ! empty( $s['mask_enabled'] );
		$mask_start       = (int) ( $s['mask_start']     ?? 90 );
		$mask_top_enabled = ! empty( $s['mask_top_enabled'] );
		$mask_top_end     = (int) ( $s['mask_top_end']   ?? 10 );

		// Budujemy gradient łączący obie maski w jednym linear-gradient
		$mask_css = '';
		if ( $mask_enabled || $mask_top_enabled ) {
			// Stops gradientu — od góry do dołu
			$stops = [];
			if ( $mask_top_enabled ) {
				$stops[] = 'transparent 0%';
				$stops[] = "#000 {$mask_top_end}%";
			} else {
				$stops[] = '#000 0%';
			}
			if ( $mask_enabled ) {
				$stops[] = "#000 {$mask_start}%";
				$stops[] = 'transparent 100%';
			} else {
				$stops[] = '#000 100%';
			}
			$gradient = 'linear-gradient(to bottom,' . implode( ',', $stops ) . ')';
			$mask_css = "-webkit-mask-image:{$gradient};mask-image:{$gradient};";
		}
		$color_defaults = [ '#F2E6DB', '#71D9E9', '#8c3dd0', '#D03F83', '#F43FF9', '#8c3dd0' ];
		$colors = [];
		for ( $i = 1; $i <= 6; $i++ ) {
			$colors[] = $this->color_hex( $s[ 'color_' . $i ] ?? null, $color_defaults[ $i - 1 ] );
		}

		$variation_map = [ 'v0' => 0, 'v1' => 1, 'v2' => 2, 'v3' => 3, 'v4' => 4, 'v5' => 5 ];
		$variation_key = $s['variation'] ?? 'v0';

		// Własny wariant
		$custom_bg = null;
		if ( $variation_key === 'custom' ) {
			$custom_bg = [
				'widthMultiplier'    => (float) ( $s['custom_width_multiplier']  ?? 0.6   ),
				'heightMultiplier'   => (float) ( $s['custom_height_multiplier'] ?? 2.0   ),
				'positionX'          => (float) ( $s['custom_position_x']        ?? 0.2   ),
				'positionY'          => (float) ( $s['custom_position_y']        ?? 0.0   ),
				'rotation'           => (float) ( $s['custom_rotation']          ?? 3.0   ),
				'shapeAmount'        => (float) ( $s['custom_shape_amount']      ?? 0.25  ),
				'distortionAmount'   => (float) ( $s['custom_distortion_amount'] ?? 0.15  ),
				'speedMultiplier'    => (float) ( $s['custom_speed_multiplier']  ?? 1.0   ),
				'timeStart'          => (float) ( $s['custom_time_start']        ?? -100.0 ),
			];
		}

		$cfg = [
			'variation'            => $variation_key === 'custom' ? 'custom' : ( $variation_map[ $variation_key ] ?? 0 ),
			'customBg'             => $custom_bg,
			'noiseEnabled'         => ! empty( $s['noise_enabled'] ),
			'noiseIntensity'       => (float) ( $s['noise_intensity'] ?? 0.08 ),
			'colors'               => $colors,
			'mouseEffect'          => (float) ( $s['mouse_effect']          ?? 1.0  ),
			'scrollFadeEnabled'    => ! empty( $s['scroll_fade_enabled'] ),
			'scrollFadeThreshold'  => (int)   ( $s['scroll_fade_threshold'] ?? 600  ),
			'scrollFadeDuration'   => (int)   ( $s['scroll_fade_duration']  ?? 500  ),
			'scrollFadeOpacity'    => (float) ( $s['scroll_fade_opacity']   ?? 0    ),
			'scrollPauseEnabled'   => ! empty( $s['scroll_pause_enabled'] ),
			'scrollPauseThreshold' => (int)   ( $s['scroll_pause_threshold'] ?? 600 ),
		];
		$cfg_js = wp_json_encode( $cfg );

		$uid = 'evk-wb-' . $this->id;

		$style = sprintf(
			'position:%s;z-index:%d;top:%s;left:%s;width:%s;height:%s;min-height:%s;pointer-events:%s;overflow:hidden;%s',
			esc_attr( $position ), $z_index,
			esc_attr( $top ), esc_attr( $left ),
			esc_attr( $width ), esc_attr( $height ),
			esc_attr( $min_height ), esc_attr( $pointer_events ),
			$mask_css
		);

		// Własny div — Bricks nadpisuje id na _root, więc renderujemy go sami.
		printf(
			'<div id="%s" class="evk-wave-bg" style="%s"></div>',
			esc_attr( $uid ),
			esc_attr( $style )
		);
		?>
<script type="module">
import * as THREE from 'https://esm.sh/three@0.185.1';
import { EffectComposer } from 'https://esm.sh/three@0.185.1/examples/jsm/postprocessing/EffectComposer.js';
import { RenderPass }     from 'https://esm.sh/three@0.185.1/examples/jsm/postprocessing/RenderPass.js';
import { ShaderPass }     from 'https://esm.sh/three@0.185.1/examples/jsm/postprocessing/ShaderPass.js';
import gsap from 'https://esm.sh/gsap@<?php echo EVK_GSAP_VERSION; ?>';

// three ≥0.152 domyślnie włącza zarządzanie kolorem: `new THREE.Color('#hex')`
// przelicza sRGB na przestrzeń liniową. Ten element podaje kolory wprost do
// własnego ShaderMaterial i renderuje przez EffectComposer bez OutputPass, więc
// nic tej konwersji nie odwraca — gradient wyszedłby ciemniejszy niż dotąd.
// Wyłączenie przywraca zachowanie sprzed 0.152, czyli dokładnie ten obraz,
// który jest na stronach dziś.
THREE.ColorManagement.enabled = false;

const CONFIG       = <?php echo $cfg_js; ?>;
const CONTAINER_ID = <?php echo wp_json_encode( $uid ); ?>;

// ── Presety — port 1:1 z referencji ──────────────────────────────────────────
const BACKGROUNDS = [
    { widthMultiplier:0.6, heightMultiplier:2,   position:{x:-0.1, y:0.3},  rotation:0.7, shapeAmount:0.20, distortionAmount:0.15, speedMultiplier:1, timeStart:0    },
    { widthMultiplier:0.4, heightMultiplier:2,   position:{x:-0.1, y:0.3},  rotation:2.7, shapeAmount:0.15, distortionAmount:0.15, speedMultiplier:1, timeStart:100  },
    { widthMultiplier:0.5, heightMultiplier:2,   position:{x:-0.2, y:0.3},  rotation:2.5, shapeAmount:0.25, distortionAmount:0.15, speedMultiplier:1, timeStart:100  },
    { widthMultiplier:0.6, heightMultiplier:2,   position:{x:0.2,  y:0},    rotation:3.5, shapeAmount:0.25, distortionAmount:0.15, speedMultiplier:1, timeStart:-100 },
    { widthMultiplier:0.5, heightMultiplier:1.9, position:{x:-0.3, y:0.3},  rotation:1.1, shapeAmount:0.25, distortionAmount:0.15, speedMultiplier:1, timeStart:-100 },
    { widthMultiplier:0.3, heightMultiplier:2,   position:{x:0.05, y:-0.1}, rotation:4.7, shapeAmount:0.25, distortionAmount:0.15, speedMultiplier:1, timeStart:-100 },
];

// ── Kolory ────────────────────────────────────────────────────────────────────
const COLORS = CONFIG.colors.map(c => new THREE.Color(c));

// ── Perlin noise GLSL — identyczny z referencją ───────────────────────────────
const perlinGLSL = `
vec2 fade(vec2 t){return t*t*t*(t*(t*6.0-15.0)+10.0);}
vec4 permute(vec4 x){return mod(((x*34.0)+1.0)*x,289.0);}
float cnoise21(vec2 P){
  vec4 Pi=floor(P.xyxy)+vec4(0.,0.,1.,1.);
  vec4 Pf=fract(P.xyxy)-vec4(0.,0.,1.,1.);
  Pi=mod(Pi,289.0);
  vec4 ix=Pi.xzxz,iy=Pi.yyww,fx=Pf.xzxz,fy=Pf.yyww;
  vec4 i=permute(permute(ix)+iy);
  vec4 gx=2.0*fract(i*0.0243902439)-1.0,gy=abs(gx)-0.5,tx=floor(gx+0.5);
  gx=gx-tx;
  vec2 g00=vec2(gx.x,gy.x),g10=vec2(gx.y,gy.y),g01=vec2(gx.z,gy.z),g11=vec2(gx.w,gy.w);
  vec4 norm=1.79284291400159-0.85373472095314*vec4(dot(g00,g00),dot(g01,g01),dot(g10,g10),dot(g11,g11));
  g00*=norm.x;g01*=norm.y;g10*=norm.z;g11*=norm.w;
  float n00=dot(g00,vec2(fx.x,fy.x)),n10=dot(g10,vec2(fx.y,fy.y)),n01=dot(g01,vec2(fx.z,fy.z)),n11=dot(g11,vec2(fx.w,fy.w));
  vec2 fade_xy=fade(Pf.xy);
  vec2 n_x=mix(vec2(n00,n01),vec2(n10,n11),fade_xy.x);
  return 2.3*mix(n_x.x,n_x.y,fade_xy.y);
}`;

// ── DotScreen post-process shader — port 1:1 + szum (grain) ─────────────────
const DotScreenShader = {
    uniforms: {
        uTime:          { value: null },
        uMouse:         { value: new THREE.Vector2(0,0) },
        uMouseEffect:   { value: 0.1 },
        uAmount:        { value: 0.15 },
        uVelocity:      { value: 0 },
        tDiffuse:       { value: null },
        tSize:          { value: new THREE.Vector2(256,256) },
        center:         { value: new THREE.Vector2(0.5,0.5) },
        angle:          { value: 1.57 },
        scale:          { value: 1 },
        uNoiseEnabled:  { value: 0.0 },
        uNoiseIntensity:{ value: 0.0 },
        uNoiseSeed:     { value: 0.0 },
    },
    vertexShader: `
        varying vec2 vUv;
        varying vec3 vPosition;
        void main(){
            vUv = uv;
            gl_Position = projectionMatrix * modelViewMatrix * vec4(position,1.);
        }`,
    fragmentShader: `
        uniform vec2 center;
        uniform float angle;
        uniform float scale;
        uniform float uTime;
        uniform float uAmount;
        uniform vec2 uMouse;
        uniform float uMouseEffect;
        uniform float uVelocity;
        uniform vec2 tSize;
        uniform float uNoiseEnabled;
        uniform float uNoiseIntensity;
        uniform float uNoiseSeed;
        float PI = ${Math.PI};
        float uRandom = ${Math.random()};
        varying vec3 vPosition;
        uniform sampler2D tDiffuse;
        varying vec2 vUv;
        ${perlinGLSL}
        void main(){
            vec2 newUv = vUv;
            vec2 p = 2.*vUv - vec2(1.);
            p += 0.3*uRandom*cos(2.*(p.yx*uRandom)+uTime);
            p += 0.4*uRandom*cos(5.*(p.xy*uRandom)+1.5*uTime);
            p += 0.2*uRandom*cos(3.7*p.yx+2.5*uTime);
            p += 0.2*cos(7.*p.yx+0.5*uTime);
            vec2 centredUv = 2.*vUv - vec2(1.);
            newUv = vUv + centredUv*vec2(1.,1.);
            newUv.x = mix(vUv.x, length(p), uAmount);
            newUv.y = mix(vUv.y, 0., uAmount);
            vec4 color = texture2D(tDiffuse, newUv);
            if (uNoiseEnabled > 0.5) {
                float grain = fract(sin(dot(newUv + uNoiseSeed, vec2(12.9898,78.233))) * 43758.5453123);
                color.rgb += (grain - 0.5) * uNoiseIntensity;
            }
            gl_FragColor = vec4(color.xyz, color.w);  // przepuszczamy alphę z siatki
        }`,
};

// ── Vertex shader siatki — port 1:1 ──────────────────────────────────────────
const vertexShader = `
    varying vec2 uPos;
    varying vec2 vUv;
    uniform vec2 uRayMouse;
    uniform float uMouseEffect;
    uniform float uRatio;
    float uTrailWidth = 0.15;
    float PI = ${Math.PI};
    void main(){
        vUv = uv;
        vec2 direction = normalize(position.xy - uRayMouse);
        float distanceToMouse = length(position.xy - uRayMouse);
        float falloff = smoothstep(0., uTrailWidth, distanceToMouse);
        float displacement = uMouseEffect * 0.1 * falloff;
        vec3 newPosition = vec3(position.xy - direction * displacement / 2., position.z);
        uPos = direction * displacement * 2.;
        gl_Position = projectionMatrix * modelViewMatrix * vec4(position, 1.0);
    }`;

// ── Fragment shader siatki — port 1:1 ────────────────────────────────────────
const fragmentShader = `
    uniform float uTime;
    uniform vec2 uRayMouse;
    uniform float uMouseEffect;
    uniform float uNoiseAmount;
    uniform float uAmount;
    uniform float uPow;
    uniform float uAlpha;
    varying vec2 vUv;
    varying vec2 uPos;
    uniform vec3 uColor[6];
    float PI = ${Math.PI};
    ${perlinGLSL}
    void main(){
        vec3 firstColor = uColor[0];
        vec2 seed = (vUv * -uPos) * mix(vUv, uPos, 30. * uAmount);
        float ml = pow(6., 0.5) * -0.01;
        float n = cnoise21(seed) + 1. * uTime;
        vec3 color;
        color = mix(firstColor, firstColor, cnoise21(seed) / 1000.);
        for(int i = 1; i < 5; i++){
            float amount = (float(i) + 1.) * 0.09;
            float n2 = smoothstep(amount*uTime+ml, amount*uTime+ml+amount*uTime, n*uTime);
            color = mix(color, uColor[i], n2);
        }
        float alpha = uAlpha * pow(sin(vUv.x * PI), uPow);
        alpha *= pow(sin(vUv.y * PI), uPow);
        gl_FragColor = vec4(color, alpha);
    }`;

// ── Klasa — port 1:1 z referencji ────────────────────────────────────────────
class EvkWaveBackground {

    constructor(container) {
        this.container     = container;
        // Wybór presetu lub własnego wariantu
        this.bg = CONFIG.variation === 'custom' && CONFIG.customBg
            ? {
                widthMultiplier:  CONFIG.customBg.widthMultiplier,
                heightMultiplier: CONFIG.customBg.heightMultiplier,
                position: { x: CONFIG.customBg.positionX, y: CONFIG.customBg.positionY },
                rotation:         CONFIG.customBg.rotation,
                shapeAmount:      CONFIG.customBg.shapeAmount,
                distortionAmount: CONFIG.customBg.distortionAmount,
                speedMultiplier:  CONFIG.customBg.speedMultiplier,
                timeStart:        CONFIG.customBg.timeStart,
              }
            : BACKGROUNDS[CONFIG.variation] || BACKGROUNDS[0];

        this.width         = container.offsetWidth;
        this.height        = container.offsetHeight;
        this.positionZ     = 4000;
        this.time          = this.bg.timeStart;
        this.uTime         = 0.1;
        this.reverseUTime  = false;
        this.allowRayMouse = false;
        this.paused        = false;
        this.rafId         = null;

        this.settings = {
            mouseEffect:      CONFIG.mouseEffect,
            distortionAmount: this.bg.distortionAmount,
            shapeAmount:      this.bg.shapeAmount,
            speedMultiplier:  this.bg.speedMultiplier,
        };

        // Scene
        this.scene = new THREE.Scene();

        // Renderer — alpha:true, przezroczyste tło; kontener może mieć własne CSS background
        this.renderer = new THREE.WebGLRenderer({ alpha: true, powerPreference: 'high-performance' });
        this.renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
        this.renderer.setSize(this.width, this.height);
        this.renderer.setClearColor(0x000000, 0);
        container.appendChild(this.renderer.domElement);

        // Camera
        this.camera = new THREE.PerspectiveCamera(40, this.width / this.height, 10, 10000);
        this.camera.position.z = this.positionZ;
        this.camera.aspect     = this.width / this.height;
        this.camera.fov        = 2 * Math.atan(this.height / 2 / this.positionZ) * (180 / Math.PI);
        this.camera.updateProjectionMatrix();

        // Mouse
        this.uMouse    = new THREE.Vector2(0, 0);
        this.rayMouse  = new THREE.Vector2(1, 1);
        this.raycaster = new THREE.Raycaster();
        this.rayXTo = gsap.quickTo(this.rayMouse, 'x', { duration: 0.75, ease: 'power1' });
        this.rayYTo = gsap.quickTo(this.rayMouse, 'y', { duration: 0.75, ease: 'power1' });

        this.initPostProcessing();
        this.addObjects();
        this.resize();
        this.render();
        this.setupResize();
        this.followMouse();
    }

    initPostProcessing() {
        this.composer = new EffectComposer(this.renderer);
        this.composer.addPass(new RenderPass(this.scene, this.camera));
        this.effect1 = new ShaderPass(DotScreenShader);
        this.effect1.uniforms.uAmount.value       = this.settings.distortionAmount;
        this.effect1.uniforms.uMouseEffect.value  = this.settings.mouseEffect;
        this.effect1.uniforms.uNoiseEnabled.value  = CONFIG.noiseEnabled ? 1.0 : 0.0;
        this.effect1.uniforms.uNoiseIntensity.value = CONFIG.noiseIntensity;
        this.composer.addPass(this.effect1);
    }

    addObjects() {
        this.material = new THREE.ShaderMaterial({
            uniforms: {
                uTime:        { value: 0 },
                uNoiseAmount: { value: 0 },
                uRayMouse:    { value: this.uMouse },   // zastępowane w render() przez rayMouse
                uAmount:      { value: this.settings.shapeAmount },
                uPow:         { value: 3 },
                uAlpha:       { value: 0 },
                uColor:       { value: COLORS },
                uMouseEffect: { value: this.settings.mouseEffect },
                uVelocity:    { value: 0 },
                uRatio:       { value: 1 },
            },
            side: THREE.DoubleSide,
            transparent: true,
            vertexShader,
            fragmentShader,
        });
        this.geometry = new THREE.PlaneGeometry(1, 1, 128, 128);
        this.plane    = new THREE.Mesh(this.geometry, this.material);
        this.camera.rotation.z = -this.bg.rotation;  // negacja — referencja renderuje w przeciwnym kierunku
        this.initResponsivePositioning();
        this.scene.add(this.plane);
    }

    initResponsivePositioning() {
        this.plane.scale.x = this.bg.widthMultiplier  * this.width;
        this.plane.scale.y = this.bg.heightMultiplier * 1.25 * this.height;
        this.plane.scale.z = this.bg.widthMultiplier  * this.width;
        this.camera.position.x = this.bg.position.x  * this.width;
        this.camera.position.y = -this.bg.position.y  * this.height;
        this.material.uniforms.uRatio.value = this.plane.scale.x / this.plane.scale.y;
    }

    setupResize() {
        window.addEventListener('resize', this.resize.bind(this));
    }

    resize() {
        this.width  = this.container.offsetWidth;
        this.height = this.container.offsetHeight;
        this.camera.aspect = this.width / this.height;
        this.camera.fov    = 2 * Math.atan(this.height / 2 / this.positionZ) * (180 / Math.PI);
        this.camera.updateProjectionMatrix();
        this.renderer.setSize(this.width, this.height);
        if (this.composer) this.composer.setSize(this.width, this.height);
        this.initResponsivePositioning();
    }

    followMouse() {
        // window — kontener ma pointer-events:none i nie odbiera eventów
        const mX = gsap.quickTo(this.uMouse, 'x', { duration: 0.5, ease: 'power1' });
        const mY = gsap.quickTo(this.uMouse, 'y', { duration: 0.5, ease: 'power1' });

        window.addEventListener('mousemove', (e) => {
            mX(e.clientX / this.width  - 0.5);
            mY(e.clientY / this.height - 0.5);
            this.checkPositionOnPlane(e);
        });
    }

    checkPositionOnPlane(e) {
        // NDC z window — jak w referencji
        const ndc = new THREE.Vector2(
            (e.clientX / window.innerWidth)  *  2 - 1,
            (e.clientY / window.innerHeight) * -2 + 1
        );
        this.raycaster.setFromCamera(ndc, this.camera);
        const hits = this.raycaster.intersectObject(this.plane);
        if (hits.length > 0 && this.allowRayMouse) {
            this.rayXTo(hits[0].point.x / this.plane.scale.x);
            this.rayYTo(hits[0].point.y / this.plane.scale.y);
        }
    }

    animateIn(duration, delay) {
        gsap.to(this.material.uniforms.uPow,   { value: 1.5, duration, delay, ease: 'power4.out' });
        gsap.to(this.material.uniforms.uAlpha, { value: 1,   duration, delay, ease: 'power4.out' });
        this.allowRayMouse = false;
        gsap.to(this.rayMouse, {
            x: -0.4, y: -0.4,
            duration,
            ease: 'power1.out',
            immediateRender: false,   // jak w referencji
            onComplete: () => { this.allowRayMouse = true; },
        });
    }

    render() {
        this.time += 0.01;

        if (this.reverseUTime) {
            this.uTime -= 0.001;
            if (this.uTime < 0.1) this.reverseUTime = false;
        } else {
            this.uTime += 0.001;
            if (this.uTime > 0.5) this.reverseUTime = true;
        }

        this.material.uniforms.uTime.value     = this.uTime;
        this.material.uniforms.uRayMouse.value = this.rayMouse;
        this.effect1.uniforms.uMouse.value     = this.uMouse;
        this.effect1.uniforms.uTime.value      = this.time * this.settings.speedMultiplier;
        if (CONFIG.noiseEnabled) {
            this.effect1.uniforms.uNoiseSeed.value = Math.random();
        }

        // Oba calle — jak w referencji
        this.renderer.render(this.scene, this.camera);
        this.composer.render();

        this.rafId = requestAnimationFrame(() => this.render());
    }

    destroy() {
        window.removeEventListener('resize', this.resize.bind(this));
        cancelAnimationFrame(this.rafId);
        this.plane.geometry.dispose();
        this.plane.material.dispose();
        this.renderer.dispose();
        if (this.container.contains(this.renderer.domElement)) {
            this.container.removeChild(this.renderer.domElement);
        }
    }

    initScrollBehavior() {
        if (CONFIG.scrollFadeEnabled) {
            const thr = CONFIG.scrollFadeThreshold;
            let lastState = null;
            this.container.style.transition = `opacity ${CONFIG.scrollFadeDuration}ms ease`;
            const onScroll = () => {
                const state = window.scrollY >= thr ? 'out' : 'in';
                if (state === lastState) return;
                lastState = state;
                this.container.style.opacity = state === 'out' ? CONFIG.scrollFadeOpacity : '';
            };
            window.addEventListener('scroll', onScroll, { passive: true });
            onScroll();
        }

        if (CONFIG.scrollPauseEnabled) {
            const thr = CONFIG.scrollPauseThreshold;
            window.addEventListener('scroll', () => {
                const shouldPause = window.scrollY >= thr;
                if (shouldPause && !this.paused) {
                    this.paused = true;
                    cancelAnimationFrame(this.rafId);
                } else if (!shouldPause && this.paused) {
                    this.paused = false;
                    this.rafId = requestAnimationFrame(() => this.render());
                }
            }, { passive: true });
        }
    }
}

// ── Boot ──────────────────────────────────────────────────────────────────────
function evkWbBoot(tries = 0) {
    const container = document.getElementById(CONTAINER_ID);
    if (container) {
        const instance = new EvkWaveBackground(container);
        instance.initScrollBehavior();
        instance.animateIn(2, 0.5);   // jak w referencji: animateIn(2, .5)
    } else if (tries < 50) {
        setTimeout(() => evkWbBoot(tries + 1), 50);
    } else {
        console.error('EvkWaveBackground: nie znaleziono #' + CONTAINER_ID);
    }
}
evkWbBoot();
</script>
<?php
	}
}
