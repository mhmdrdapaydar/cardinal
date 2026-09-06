/**
 * Cardinal — extra place detail for the 3D world.
 * =============================================================================
 * Injected into both world chunks by tools/build_graphics_release.py. Written
 * as a factory so one source serves the modern and the legacy (ES5) bundle:
 * the caller passes in that bundle's JSX runtime, React and useFrame.
 *
 * ES5 ONLY. No arrow functions, no let/const, no template literals, no spread.
 *
 * COLLISION RULE
 * --------------
 * The build ships its own collision volumes for the avatar and knows nothing
 * about anything added here. So every object below is either
 *
 *   - at least 5 units above the ground, or
 *   - at radius 55+, far outside where the player can walk,
 *
 * which means the player can never clip through a new prop. Nothing here is
 * interactive, nothing reads or writes game state, and nothing casts shadows
 * (the shadow frustum is +/-26 and these all sit outside or above it).
 */

function cardinalMakePlaces(J, R, useFrame) {
  // deterministic pseudo-random, so the layout is identical every mount
  function rnd(n) {
    var x = Math.sin(n * 127.1 + 311.7) * 43758.5453;
    return x - Math.floor(x);
  }

  function tier(quality, high, balanced, low) {
    if (quality === "high") return high;
    if (quality === "balanced") return balanced;
    return low;
  }

  // ------------------------------------------------------- outer district
  // The player is clamped to radius 33.25 in the city and 60 in the wild by
  // kC(), so anything past that is visible but unreachable and needs no
  // collider. That band is where a city should actually look like a city
  // instead of one ring of huts, so this fills it with real architecture.
  function OuterDistrict(props) {
    var palette = props.palette;
    var city = props.city;
    var count = props.count;
    var inner = city ? 36 : 63;
    var spread = city ? 15 : 26;
    var items = [];
    for (var i = 0; i < count; i++) {
      var a = (i / count) * Math.PI * 2 + rnd(i + 3) * 0.16;
      var r = inner + rnd(i + 30) * spread;
      var kind = i % 4;
      var w = (city ? 4.4 : 6.2) + rnd(i + 11) * (city ? 3.4 : 5);
      var d = w * (0.75 + rnd(i + 19) * 0.5);
      var base = (city ? 5.5 : 6) + rnd(i + 5) * (city ? 6.5 : 9);
      var tierH = base * 0.62;
      var lit = rnd(i + 47) > 0.35;
      var parts = [
        // ground storey
        J.jsxs("mesh", {
          position: [0, base / 2, 0],
          children: [
            J.jsx("boxGeometry", { args: [w, base, d] }),
            J.jsx("meshStandardMaterial", { color: palette.stone, roughness: 0.92, metalness: 0.04 })
          ]
        }, "a"),
        // upper storey, stepped in
        J.jsxs("mesh", {
          position: [0, base + tierH / 2, 0],
          children: [
            J.jsx("boxGeometry", { args: [w * 0.76, tierH, d * 0.76] }),
            J.jsx("meshStandardMaterial", { color: palette.roof, roughness: 0.9, metalness: 0.04 })
          ]
        }, "b")
      ];
      // roof: pyramid, hip or a tower spire depending on the variant
      if (kind === 3) {
        parts.push(J.jsxs("mesh", {
          position: [0, base + tierH + w * 0.9, 0],
          children: [
            J.jsx("coneGeometry", { args: [w * 0.5, w * 1.8, 6] }),
            J.jsx("meshStandardMaterial", { color: palette.accent, roughness: 0.62, metalness: 0.22 })
          ]
        }, "c"));
      } else {
        parts.push(J.jsxs("mesh", {
          position: [0, base + tierH + w * 0.34, 0],
          rotation: [0, Math.PI * 0.25, 0],
          children: [
            J.jsx("coneGeometry", { args: [w * 0.68, w * 0.7, 4] }),
            J.jsx("meshStandardMaterial", { color: palette.roof, roughness: 0.88 })
          ]
        }, "c"));
      }
      // lit windows: one emissive slab facing the plaza reads as a whole
      // facade of windows at this distance, for one draw call
      if (lit) {
        parts.push(J.jsxs("mesh", {
          position: [0, base * 0.55, d / 2 + 0.06],
          children: [
            J.jsx("planeGeometry", { args: [w * 0.62, base * 0.42] }),
            J.jsx("meshStandardMaterial", {
              color: "#ffe1a8",
              emissive: palette.accent,
              emissiveIntensity: 1.25,
              roughness: 0.55
            })
          ]
        }, "d"));
      }
      // a few get a beacon on top
      if (kind === 1) {
        parts.push(J.jsxs("mesh", {
          position: [0, base + tierH + w * 1.9, 0],
          children: [
            J.jsx("octahedronGeometry", { args: [0.7, 0] }),
            J.jsx("meshStandardMaterial", {
              color: palette.crystal,
              emissive: palette.crystal,
              emissiveIntensity: 2,
              roughness: 0.25
            })
          ]
        }, "e"));
      }
      items.push(
        J.jsxs("group", {
          position: [Math.cos(a) * r, -0.4, Math.sin(a) * r],
          rotation: [0, -a + Math.PI * 0.5, 0],
          children: parts
        }, i)
      );
    }
    return J.jsxs("group", { children: items });
  }

  // ---------------------------------------------------------------- horizon
  // Far silhouettes behind the outer district. Kept inside radius 70 so they
  // still stand on the 150x150 ground plane rather than floating off its edge.
  function Skyline(props) {
    var palette = props.palette;
    var city = props.city;
    var count = props.count;
    var items = [];
    for (var i = 0; i < count; i++) {
      var a = (i / count) * Math.PI * 2 + rnd(i) * 0.3;
      var r = 55 + rnd(i + 90) * 14;
      var hgt = city ? 19 + rnd(i + 7) * 34 : 24 + rnd(i + 7) * 44;
      var w = city ? 3.2 + rnd(i + 21) * 4.6 : 5 + rnd(i + 21) * 7;
      var capH = city ? 5.5 + rnd(i + 33) * 4 : 9 + rnd(i + 33) * 8;
      var lit = rnd(i + 55) > 0.55;
      items.push(
        J.jsxs("group", {
          position: [Math.cos(a) * r, -1.5, Math.sin(a) * r],
          rotation: [0, rnd(i + 12) * 1.4, 0],
          children: [
            J.jsxs("mesh", {
              position: [0, hgt / 2, 0],
              children: [
                J.jsx("boxGeometry", { args: [w, hgt, w] }),
                J.jsx("meshStandardMaterial", { color: palette.roof, roughness: 0.96, metalness: 0.02 })
              ]
            }),
            J.jsxs("mesh", {
              position: [0, hgt + capH / 2, 0],
              children: [
                J.jsx("coneGeometry", { args: [w * 0.8, capH, city ? 5 : 4] }),
                J.jsx("meshStandardMaterial", { color: palette.stone, roughness: 0.92, metalness: 0.05 })
              ]
            }),
            lit
              ? J.jsxs("mesh", {
                  position: [0, hgt + capH + 1.1, 0],
                  children: [
                    J.jsx("octahedronGeometry", { args: [0.85, 0] }),
                    J.jsx("meshStandardMaterial", {
                      color: palette.crystal,
                      emissive: palette.crystal,
                      emissiveIntensity: 1.6,
                      roughness: 0.3
                    })
                  ]
                })
              : null
          ]
        }, i)
      );
    }
    return J.jsxs("group", { children: items });
  }

  // -------------------------------------------------------- rooftop details
  // The build lays its houses out with a closed-form expression, so their exact
  // transforms are recoverable and can be decorated precisely:
  //
  //   a = i/16*2PI + 0.12 ; r = 23.7 + (i%4)*1.85
  //   pos = [cos(a)*r, 0, sin(a)*r] ; yaw = a + PI/2
  //   w = 4.6 + (i%3)*0.68 ; d = 4.2 + ((i+1)%2)*0.52 ; h = 3.55 + (i%3)*0.42
  //
  // Only the first `count` houses are actually built (14 high / 12 balanced /
  // 7 low), and the collider list uses the same slice, so decorating the same
  // range keeps visuals and collision in agreement. Everything added sits on
  // the roof or above head height, and hangs off a building that already has
  // its own collider, so nothing new can be walked into.
  function Rooftops(props) {
    var palette = props.palette;
    var count = props.count;
    var items = [];
    for (var i = 0; i < count; i++) {
      var a = (i / 16) * Math.PI * 2 + 0.12;
      var r = 23.7 + (i % 4) * 1.85;
      var w = 4.6 + (i % 3) * 0.68;
      var d = 4.2 + ((i + 1) % 2) * 0.52;
      var hgt = 3.55 + (i % 3) * 0.42;
      var variant = i % 8;
      var parts = [];

      // ridge beam along the roof line
      parts.push(J.jsxs("mesh", {
        position: [0, hgt + 0.92, 0],
        children: [
          J.jsx("boxGeometry", { args: [w * 0.92, 0.16, 0.2] }),
          J.jsx("meshStandardMaterial", { color: "#6d5738", roughness: 0.84 })
        ]
      }, "ridge"));

      // chimney, offset so it does not sit dead centre
      parts.push(J.jsxs("mesh", {
        position: [w * 0.26, hgt + 1.35, d * 0.18],
        children: [
          J.jsx("boxGeometry", { args: [0.44, 1.35, 0.44] }),
          J.jsx("meshStandardMaterial", { color: palette.stone, roughness: 0.95 })
        ]
      }, "chim"));
      parts.push(J.jsxs("mesh", {
        position: [w * 0.26, hgt + 2.08, d * 0.18],
        children: [
          J.jsx("boxGeometry", { args: [0.58, 0.14, 0.58] }),
          J.jsx("meshStandardMaterial", { color: "#3b4757", roughness: 0.9 })
        ]
      }, "chimcap"));

      // roof lantern on every second house
      if (variant % 2 === 0) {
        parts.push(J.jsxs("mesh", {
          position: [-w * 0.3, hgt + 1.28, -d * 0.2],
          children: [
            J.jsx("boxGeometry", { args: [0.3, 0.42, 0.3] }),
            J.jsx("meshStandardMaterial", {
              color: "#ffe3ad",
              emissive: palette.accent,
              emissiveIntensity: 1.7,
              roughness: 0.4
            })
          ]
        }, "lant"));
      }

      // hanging shop sign on a bracket, well above head height
      if (variant === 1 || variant === 6 || variant === 2) {
        parts.push(J.jsxs("mesh", {
          position: [0, 3.15, d / 2 + 0.42],
          children: [
            J.jsx("boxGeometry", { args: [0.08, 0.08, 0.84] }),
            J.jsx("meshStandardMaterial", { color: "#4a3a26", roughness: 0.9 })
          ]
        }, "brk"));
        parts.push(J.jsxs("mesh", {
          position: [0, 2.72, d / 2 + 0.8],
          children: [
            J.jsx("boxGeometry", { args: [1.05, 0.62, 0.07] }),
            J.jsx("meshStandardMaterial", {
              color: variant === 6 ? "#8a5a2f" : palette.accent,
              emissive: palette.accent,
              emissiveIntensity: 0.4,
              roughness: 0.72
            })
          ]
        }, "sign"));
      }

      // banner hung down the facade
      if (variant === 0 || variant === 4 || variant === 7) {
        parts.push(J.jsxs("mesh", {
          position: [w * 0.3, hgt - 0.55, d / 2 + 0.05],
          children: [
            J.jsx("planeGeometry", { args: [0.7, 2.1] }),
            J.jsx("meshStandardMaterial", {
              color: variant === 4 ? palette.accentSoft : "#b3453f",
              emissive: variant === 4 ? palette.accentSoft : "#6d1f1c",
              emissiveIntensity: 0.3,
              roughness: 0.85,
              side: 2
            })
          ]
        }, "ban"));
      }

      // eave lamp over the door, above head height
      parts.push(J.jsxs("mesh", {
        position: [-w * 0.22, 2.95, d / 2 + 0.14],
        children: [
          J.jsx("sphereGeometry", { args: [0.17, 8, 7] }),
          J.jsx("meshStandardMaterial", {
            color: "#ffdca6",
            emissive: palette.accent,
            emissiveIntensity: 2.1,
            roughness: 0.35
          })
        ]
      }, "eave"));

      items.push(
        J.jsxs("group", {
          position: [Math.cos(a) * r, 0, Math.sin(a) * r],
          rotation: [0, a + Math.PI * 0.5, 0],
          children: parts
        }, i)
      );
    }
    return J.jsxs("group", { children: items });
  }

  // ------------------------------------------------------------ sky lanterns
  // Paper lanterns drifting up over the city. The signature anime festival
  // image, and it fills the empty middle distance between roofs and sky.
  function SkyLanterns(props) {
    var palette = props.palette;
    var count = props.count;
    var group = R.useRef(null);
    useFrame(function (state) {
      if (!group.current) return;
      var t = state.clock.elapsedTime;
      var kids = group.current.children;
      for (var i = 0; i < kids.length; i++) {
        var speed = 0.32 + rnd(i + 3) * 0.4;
        var span = 22;
        var base = 13 + rnd(i + 17) * 6;
        kids[i].position.y = base + ((t * speed + rnd(i) * span) % span);
        kids[i].position.x = kids[i].userData.bx + Math.sin(t * 0.28 + i) * 1.5;
        kids[i].rotation.y = t * 0.18 + i;
      }
    });
    var items = [];
    for (var i = 0; i < count; i++) {
      var a = (i / count) * Math.PI * 2 + rnd(i + 41) * 0.8;
      var r = 17 + rnd(i + 5) * 30;
      var bx = Math.cos(a) * r;
      var s = 0.3 + rnd(i + 61) * 0.26;
      items.push(
        J.jsxs("group", {
          position: [bx, 12, Math.sin(a) * r],
          userData: { bx: bx },
          children: [
            J.jsxs("mesh", {
              children: [
                J.jsx("cylinderGeometry", { args: [s * 0.72, s * 0.58, s * 1.25, 7] }),
                J.jsx("meshStandardMaterial", {
                  color: "#ffd9a1",
                  emissive: palette.accent,
                  emissiveIntensity: 0.95,
                  roughness: 0.5,
                  transparent: true,
                  opacity: 0.92
                })
              ]
            }),
            J.jsxs("mesh", {
              position: [0, -s * 0.78, 0],
              children: [
                J.jsx("coneGeometry", { args: [s * 0.5, s * 0.42, 6] }),
                J.jsx("meshStandardMaterial", { color: "#6c4f34", roughness: 0.85 })
              ]
            })
          ]
        }, i)
      );
    }
    return J.jsxs("group", { ref: group, children: items });
  }

  // ------------------------------------------------------------ lantern lines
  // Garlands strung high across the plaza. Sits at 7-9 units, well clear of the
  // avatar, and frames the square from above.
  function Garlands(props) {
    var palette = props.palette;
    var lines = props.lines;
    var perLine = props.perLine;
    var out = [];
    for (var l = 0; l < lines; l++) {
      var a0 = (l / lines) * Math.PI * 2;
      var a1 = a0 + Math.PI * 0.5;
      var r0 = 15 + rnd(l) * 4;
      var x0 = Math.cos(a0) * r0;
      var z0 = Math.sin(a0) * r0;
      var x1 = Math.cos(a1) * r0;
      var z1 = Math.sin(a1) * r0;
      var beads = [];
      for (var i = 0; i <= perLine; i++) {
        var t = i / perLine;
        // catenary sag
        var sag = Math.sin(t * Math.PI) * 2.7;
        beads.push(
          J.jsxs("mesh", {
            position: [x0 + (x1 - x0) * t, 8.1 - sag, z0 + (z1 - z0) * t],
            children: [
              J.jsx("sphereGeometry", { args: [0.15, 7, 6] }),
              J.jsx("meshStandardMaterial", {
                color: i % 3 === 0 ? palette.accentSoft : "#ffd9a1",
                emissive: i % 3 === 0 ? palette.accentSoft : palette.accent,
                emissiveIntensity: 1.15,
                roughness: 0.35
              })
            ]
          }, i)
        );
      }
      out.push(J.jsxs("group", { children: beads }, l));
    }
    return J.jsxs("group", { children: out });
  }

  // ------------------------------------------------------------ sky monoliths
  // Slowly turning crystal shards above the square. Reads as the "system"
  // architecture of the realm rather than scenery.
  function Monoliths(props) {
    var palette = props.palette;
    var count = props.count;
    var group = R.useRef(null);
    useFrame(function (state) {
      if (!group.current) return;
      var t = state.clock.elapsedTime;
      group.current.rotation.y = t * 0.045;
      var kids = group.current.children;
      for (var i = 0; i < kids.length; i++) {
        kids[i].rotation.x = t * (0.12 + rnd(i) * 0.16);
        kids[i].rotation.z = t * (0.09 + rnd(i + 4) * 0.13);
        kids[i].position.y = kids[i].userData.by + Math.sin(t * 0.5 + i * 1.7) * 0.75;
      }
    });
    var items = [];
    for (var i = 0; i < count; i++) {
      var a = (i / count) * Math.PI * 2;
      var r = 11 + rnd(i + 71) * 9;
      var by = 13 + rnd(i + 23) * 7;
      var s = 0.7 + rnd(i + 13) * 1.15;
      items.push(
        J.jsxs("mesh", {
          position: [Math.cos(a) * r, by, Math.sin(a) * r],
          userData: { by: by },
          children: [
            J.jsx("octahedronGeometry", { args: [s, 0] }),
            J.jsx("meshStandardMaterial", {
              color: palette.crystal,
              emissive: palette.crystal,
              emissiveIntensity: 1.5,
              roughness: 0.18,
              metalness: 0.35,
              transparent: true,
              opacity: 0.88
            })
          ]
        }, i)
      );
    }
    return J.jsxs("group", { ref: group, children: items });
  }

  // ------------------------------------------------------------ floating isles
  // Wild-realm counterpart: broken rock shelves hanging in the air.
  function FloatingIsles(props) {
    var palette = props.palette;
    var count = props.count;
    var group = R.useRef(null);
    useFrame(function (state) {
      if (!group.current) return;
      var t = state.clock.elapsedTime;
      var kids = group.current.children;
      for (var i = 0; i < kids.length; i++) {
        kids[i].position.y = kids[i].userData.by + Math.sin(t * 0.32 + i * 2.1) * 0.9;
        kids[i].rotation.y = t * 0.03 * (i % 2 ? 1 : -1) + i;
      }
    });
    var items = [];
    for (var i = 0; i < count; i++) {
      var a = (i / count) * Math.PI * 2 + rnd(i + 8) * 0.6;
      var r = 22 + rnd(i + 44) * 22;
      var by = 15 + rnd(i + 66) * 12;
      var s = 2.4 + rnd(i + 88) * 3.4;
      items.push(
        J.jsxs("group", {
          position: [Math.cos(a) * r, by, Math.sin(a) * r],
          userData: { by: by },
          children: [
            J.jsxs("mesh", {
              scale: [1, 0.42, 1],
              children: [
                J.jsx("icosahedronGeometry", { args: [s, 0] }),
                J.jsx("meshStandardMaterial", { color: palette.ground, roughness: 0.95, flatShading: true })
              ]
            }),
            J.jsxs("mesh", {
              position: [0, s * 0.3, 0],
              scale: [1, 0.3, 1],
              children: [
                J.jsx("icosahedronGeometry", { args: [s * 0.78, 0] }),
                J.jsx("meshStandardMaterial", { color: palette.groundHigh, roughness: 0.9, flatShading: true })
              ]
            }),
            J.jsxs("mesh", {
              position: [0, -s * 0.62, 0],
              children: [
                J.jsx("coneGeometry", { args: [s * 0.55, s * 1.5, 5] }),
                J.jsx("meshStandardMaterial", { color: palette.groundDark, roughness: 0.98, flatShading: true })
              ]
            })
          ]
        }, i)
      );
    }
    return J.jsxs("group", { ref: group, children: items });
  }

  // --------------------------------------------------------------- entrypoint
  return function CardinalPlaces(props) {
    var quality = props.quality;
    var palette = props.palette;
    var city = props.city;
    if (quality === "low") {
      // device-saver path: static silhouettes only, nothing animated
      return J.jsxs("group", { children: [
        J.jsx(OuterDistrict, { palette: palette, city: city, count: city ? 8 : 6 }, "dist"),
        J.jsx(Skyline, { palette: palette, city: city, count: city ? 8 : 6 }, "sky")
      ] });
    }
    var children = [
      J.jsx(OuterDistrict, { palette: palette, city: city, count: tier(quality, city ? 13 : 11, city ? 9 : 7, 0) }, "dist"),
      J.jsx(Skyline, { palette: palette, city: city, count: tier(quality, city ? 16 : 14, city ? 10 : 9, 0) }, "sky")
    ];
    if (city) {
      children.push(J.jsx(Rooftops, { palette: palette, count: tier(quality, 14, 12, 7) }, "roof"));
      children.push(J.jsx(Garlands, { palette: palette, lines: tier(quality, 4, 2, 0), perLine: tier(quality, 9, 7, 0) }, "gar"));
      children.push(J.jsx(SkyLanterns, { palette: palette, count: tier(quality, 16, 8, 0) }, "lan"));
      children.push(J.jsx(Monoliths, { palette: palette, count: tier(quality, 9, 5, 0) }, "mon"));
    } else {
      children.push(J.jsx(FloatingIsles, { palette: palette, count: tier(quality, 7, 4, 0) }, "isl"));
      children.push(J.jsx(Monoliths, { palette: palette, count: tier(quality, 6, 3, 0) }, "mon"));
    }
    return J.jsxs("group", { children: children });
  };
}
