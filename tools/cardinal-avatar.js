/**
 * Cardinal — detailed avatar parts.
 * =============================================================================
 * Replaces the blobbiest pieces of the shipped avatar (hair, face) and layers
 * an outfit over it. Injected into both world chunks by
 * tools/build_graphics_release.py as an ES5 factory, so one source serves the
 * modern and the legacy bundle.
 *
 * ES5 ONLY. No arrow functions, no let/const, no template literals, no spread.
 *
 * WHAT IS DELIBERATELY LEFT ALONE
 * -------------------------------
 * The avatar's skeleton -- the limb groups driven by refs in the walk cycle,
 * the body capsule, the ground shadow, the name label height and the point
 * light -- is untouched. Everything here either replaces a purely decorative
 * mesh or is added as a new child, so the existing animation keeps working and
 * the avatar's footprint and collision radius do not change.
 *
 * The character faces -Z: the cape sits at +Z and the eyes at -0.32Z.
 */

function cardinalMakeAvatar(J, R, useFrame) {
  // ------------------------------------------------------------------ hair
  // The build had a squashed hemisphere plus three blobs. Anime hair reads
  // through its silhouette, so this is built from tapered locks: swept bangs
  // over the brow, side locks past the jaw, and a ponytail with secondary
  // motion that lags the body.
  function Hair(props) {
    var hair = props.hair;
    var visual = props.visual;
    var tail = R.useRef(null);
    var bangs = R.useRef(null);
    useFrame(function (state) {
      var t = state.clock.elapsedTime;
      if (tail.current) {
        tail.current.rotation.x = 0.22 + Math.sin(t * 1.7) * 0.09;
        tail.current.rotation.z = Math.sin(t * 1.15) * 0.07;
      }
      if (bangs.current) {
        bangs.current.rotation.x = Math.sin(t * 1.45) * 0.022;
      }
    });

    function lock(key, pos, rot, scale, len, tone) {
      return J.jsxs("mesh", {
        castShadow: true,
        position: pos,
        rotation: rot,
        scale: scale,
        children: [
          J.jsx("coneGeometry", { args: [0.5, len, 5] }),
          J.jsx("meshStandardMaterial", { color: tone || hair, roughness: 0.78, metalness: 0.06 })
        ]
      }, key);
    }

    var bangPieces = [];
    var i;
    // Five short, tapered locks lying against the brow. Cones pointing down
    // read as spikes at this scale, so these are thin boxes angled forward.
    for (i = 0; i < 5; i++) {
      var f = (i - 2) / 2;
      bangPieces.push(
        J.jsxs("mesh", {
          castShadow: true,
          position: [f * 0.19, 2.155 - Math.abs(f) * 0.012, -0.255 + Math.abs(f) * 0.055],
          rotation: [0.32, -f * 0.32, f * 0.3],
          children: [
            J.jsx("boxGeometry", { args: [0.12, 0.3, 0.09] }),
            J.jsx("meshStandardMaterial", { color: hair, roughness: 0.78, metalness: 0.06 })
          ]
        }, "b" + i)
      );
    }
    // short side locks along the cheek
    bangPieces.push(J.jsxs("mesh", {
      castShadow: true, position: [-0.28, 2.02, -0.13], rotation: [0.1, 0, -0.14],
      children: [
        J.jsx("boxGeometry", { args: [0.1, 0.34, 0.12] }),
        J.jsx("meshStandardMaterial", { color: hair, roughness: 0.78 })
      ]
    }, "sl"));
    bangPieces.push(J.jsxs("mesh", {
      castShadow: true, position: [0.28, 2.02, -0.13], rotation: [0.1, 0, 0.14],
      children: [
        J.jsx("boxGeometry", { args: [0.1, 0.34, 0.12] }),
        J.jsx("meshStandardMaterial", { color: hair, roughness: 0.78 })
      ]
    }, "sr"));

    var tailPieces = [];
    for (i = 0; i < 4; i++) {
      var s = 1 - i * 0.19;
      tailPieces.push(lock("t" + i, [0, -0.1 - i * 0.19, 0.03 + i * 0.07],
                           [Math.PI - 0.22 - i * 0.1, 0, 0], [0.26 * s, 1, 0.22 * s], 0.32));
    }

    return J.jsxs("group", { children: [
      // back volume of the hair, sitting over the skull
      J.jsxs("mesh", {
        castShadow: true,
        position: [0, 2.19, 0.05],
        scale: [1.1, 0.86, 1.08],
        children: [
          J.jsx("sphereGeometry", { args: [0.345, 18, 14] }),
          J.jsx("meshStandardMaterial", { color: hair, roughness: 0.8, metalness: 0.05 })
        ]
      }, "cap"),
      // a lighter sheen band, the painted highlight anime hair always has
      J.jsxs("mesh", {
        position: [0, 2.33, -0.02],
        rotation: [Math.PI / 2, 0, 0],
        scale: [1.06, 1.02, 1],
        children: [
          J.jsx("torusGeometry", { args: [0.235, 0.028, 5, 18, Math.PI * 1.15] }),
          J.jsx("meshStandardMaterial", {
            color: "#ffffff",
            emissive: visual.glow,
            emissiveIntensity: 0.35,
            transparent: true,
            opacity: 0.34,
            roughness: 0.3
          })
        ]
      }, "sheen"),
      J.jsxs("group", { ref: bangs, children: bangPieces }, "bangs"),
      J.jsxs("group", { ref: tail, position: [0, 2.2, 0.2], children: tailPieces }, "tail"),
      // hair ornament
      J.jsxs("mesh", {
        position: [0.27, 2.28, -0.13],
        rotation: [0, 0, 0.4],
        children: [
          J.jsx("octahedronGeometry", { args: [0.075, 0] }),
          J.jsx("meshStandardMaterial", {
            color: visual.color,
            emissive: visual.glow,
            emissiveIntensity: 1.9,
            metalness: 0.8,
            roughness: 0.12
          })
        ]
      }, "pin")
    ] });
  }

  // ------------------------------------------------------------------ face
  // Two spheres became elongated anime eyes with an iris, a specular catch
  // light and brows. Small pieces, but this is what the eye reads first.
  function Face(props) {
    var visual = props.visual;
    var hair = props.hair;
    var eye = function (side) {
      var x = side * 0.115;
      return J.jsxs("group", { position: [x, 1.995, -0.3], children: [
        // sclera
        J.jsxs("mesh", {
          scale: [0.052, 0.082, 0.03],
          children: [
            J.jsx("sphereGeometry", { args: [1, 10, 8] }),
            J.jsx("meshStandardMaterial", { color: "#f7fbff", roughness: 0.42 })
          ]
        }, "s"),
        // iris
        J.jsxs("mesh", {
          position: [0, -0.004, -0.026],
          scale: [0.036, 0.058, 0.02],
          children: [
            J.jsx("sphereGeometry", { args: [1, 10, 8] }),
            J.jsx("meshStandardMaterial", {
              color: visual.color,
              emissive: visual.glow,
              emissiveIntensity: 1.5,
              roughness: 0.24
            })
          ]
        }, "i"),
        // catch light
        J.jsxs("mesh", {
          position: [side * -0.014, 0.026, -0.04],
          children: [
            J.jsx("sphereGeometry", { args: [0.014, 6, 6] }),
            J.jsx("meshBasicMaterial", { color: "#ffffff" })
          ]
        }, "c"),
        // upper lash line
        J.jsxs("mesh", {
          position: [0, 0.062, -0.026],
          rotation: [0, 0, side * -0.2],
          children: [
            J.jsx("boxGeometry", { args: [0.115, 0.019, 0.012] }),
            J.jsx("meshStandardMaterial", { color: hair, roughness: 0.7 })
          ]
        }, "l")
      ] }, side);
    };
    return J.jsxs("group", { children: [
      eye(-1),
      eye(1),
      // brows
      J.jsxs("mesh", {
        position: [-0.118, 2.088, -0.302],
        rotation: [0, 0, 0.16],
        children: [
          J.jsx("boxGeometry", { args: [0.1, 0.02, 0.014] }),
          J.jsx("meshStandardMaterial", { color: hair, roughness: 0.75 })
        ]
      }, "bl"),
      J.jsxs("mesh", {
        position: [0.118, 2.088, -0.302],
        rotation: [0, 0, -0.16],
        children: [
          J.jsx("boxGeometry", { args: [0.1, 0.02, 0.014] }),
          J.jsx("meshStandardMaterial", { color: hair, roughness: 0.75 })
        ]
      }, "br"),
      // nose
      J.jsxs("mesh", {
        position: [0, 1.925, -0.328],
        scale: [0.05, 0.07, 0.045],
        children: [
          J.jsx("sphereGeometry", { args: [1, 8, 6] }),
          J.jsx("meshStandardMaterial", { color: "#c08a76", roughness: 0.72 })
        ]
      }, "n"),
      // mouth
      J.jsxs("mesh", {
        position: [0, 1.868, -0.318],
        children: [
          J.jsx("boxGeometry", { args: [0.055, 0.012, 0.01] }),
          J.jsx("meshStandardMaterial", { color: "#9c5e58", roughness: 0.7 })
        ]
      }, "m")
    ] });
  }

  // ---------------------------------------------------------------- outfit
  // NOT MOUNTED. Kept for reference: at the shipped camera distance these
  // layers read as clutter on a body this small rather than as tailoring, so
  // the release only installs Hair and Face.
  function Outfit(props) {
    var visual = props.visual;
    var skirt = R.useRef(null);
    useFrame(function (state) {
      if (!skirt.current) return;
      var t = state.clock.elapsedTime;
      var kids = skirt.current.children;
      for (var i = 0; i < kids.length; i++) {
        kids[i].rotation.x = kids[i].userData.rx + Math.sin(t * 2.1 + i * 0.9) * 0.055;
      }
    });

    var panels = [];
    var n = 7;
    for (var i = 0; i < n; i++) {
      var a = (i / n) * Math.PI * 2 + Math.PI / n;
      var rx = 0.12;
      panels.push(
        J.jsxs("mesh", {
          position: [Math.sin(a) * 0.215, -0.14, Math.cos(a) * 0.185],
          rotation: [rx, a, 0],
          userData: { rx: rx },
          castShadow: true,
          children: [
            J.jsx("boxGeometry", { args: [0.17, 0.33, 0.025] }),
            J.jsx("meshStandardMaterial", {
              color: visual.cloak,
              emissive: visual.color,
              emissiveIntensity: 0.16,
              roughness: 0.6,
              metalness: 0.12
            })
          ]
        }, i)
      );
    }

    return J.jsxs("group", { children: [
      // standing collar
      J.jsxs("mesh", {
        position: [0, 1.63, -0.02],
        rotation: [0.16, 0, 0],
        children: [
          J.jsx("cylinderGeometry", { args: [0.28, 0.23, 0.3, 12, 1, true] }),
          J.jsx("meshStandardMaterial", {
            color: visual.cloak,
            emissive: visual.color,
            emissiveIntensity: 0.3,
            roughness: 0.5,
            metalness: 0.28,
            side: 2
          })
        ]
      }, "collar"),
      // chest emblem
      J.jsxs("mesh", {
        position: [0, 1.36, -0.28],
        rotation: [0, 0, Math.PI * 0.25],
        children: [
          J.jsx("boxGeometry", { args: [0.085, 0.085, 0.025] }),
          J.jsx("meshStandardMaterial", {
            color: visual.color,
            emissive: visual.glow,
            emissiveIntensity: 1.7,
            metalness: 0.8,
            roughness: 0.15
          })
        ]
      }, "emblem"),
      // coat skirt
      J.jsxs("group", { ref: skirt, position: [0, 0.93, 0], children: panels }, "skirt"),
      // thigh straps
      J.jsxs("mesh", {
        position: [-0.18, 0.62, 0.02],
        rotation: [Math.PI / 2, 0, 0],
        children: [
          J.jsx("torusGeometry", { args: [0.135, 0.022, 5, 12] }),
          J.jsx("meshStandardMaterial", { color: "#3a2b20", roughness: 0.8 })
        ]
      }, "stl"),
      J.jsxs("mesh", {
        position: [0.18, 0.62, 0.02],
        rotation: [Math.PI / 2, 0, 0],
        children: [
          J.jsx("torusGeometry", { args: [0.135, 0.022, 5, 12] }),
          J.jsx("meshStandardMaterial", { color: "#3a2b20", roughness: 0.8 })
        ]
      }, "str")
    ] });
  }

  return { Hair: Hair, Face: Face, Outfit: Outfit };
}
