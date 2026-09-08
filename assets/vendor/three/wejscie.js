/**
 * Źródło paczki three.js dla elementu Wave Background.
 *
 * Wylicza DOKŁADNIE te symbole, których element używa — nic więcej. Dołożenie
 * w elemencie czegoś nowego z three.js wymaga dopisania tego tutaj, inaczej
 * paczka tego nie będzie miała i moduł wywróci się na `undefined`.
 *
 * Jak z tego zbudować plik: assets/vendor/README.md, sekcja „Podbicie three.js".
 */
export {
  Scene, PerspectiveCamera, WebGLRenderer, ShaderMaterial, PlaneGeometry,
  Mesh, Color, Vector2, Raycaster, DoubleSide, ColorManagement,
} from 'three';

export { EffectComposer } from 'three/examples/jsm/postprocessing/EffectComposer.js';
export { RenderPass }     from 'three/examples/jsm/postprocessing/RenderPass.js';
export { ShaderPass }     from 'three/examples/jsm/postprocessing/ShaderPass.js';
