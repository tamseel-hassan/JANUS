"use client";

import React, { useEffect, useRef } from "react";

interface GradientShaderBackgroundProps {
  className?: string;
  speed?: number;
  intensity?: number;
  tiltAngle?: number; // Tilt angle in degrees (e.g. -25)
  barCount?: number; // Number of contiguous tilted bars across screen
  isDark?: boolean; // Current theme state for procedural radial glow transition
}

export const GradientShaderBackground: React.FC<
  GradientShaderBackgroundProps
> = ({
  className = "",
  speed = 0.5,
  intensity = 0.8,
  tiltAngle = -25,
  barCount = 7,
  isDark = true,
}) => {
  const canvasRef = useRef<HTMLCanvasElement | null>(null);

  // Store persistent theme refs so WebGL shader context never needs to unmount/recompile on toggle
  const isDarkRef = useRef<boolean>(isDark);
  const prevIsDarkRef = useRef<boolean>(isDark);
  const transitionStartRef = useRef<number>(performance.now() - 5000); // start fully transitioned

  useEffect(() => {
    if (isDarkRef.current !== isDark) {
      prevIsDarkRef.current = isDarkRef.current;
      isDarkRef.current = isDark;
      transitionStartRef.current = performance.now();
    }
  }, [isDark]);

  useEffect(() => {
    const canvas = canvasRef.current;
    if (!canvas) return;

    let gl =
      canvas.getContext("webgl") ||
      (canvas.getContext("experimental-webgl") as WebGLRenderingContext | null);

    // Fallback Canvas 2D animation
    if (!gl) {
      let animId: number;
      const ctx = canvas.getContext("2d");
      if (!ctx) return;

      let time = 0;
      const drawFallback = () => {
        time += 0.01 * speed;
        const w = (canvas.width =
          canvas.parentElement?.clientWidth || window.innerWidth);
        const h = (canvas.height =
          canvas.parentElement?.clientHeight || window.innerHeight);

        ctx.clearRect(0, 0, w, h);
        ctx.save();
        ctx.translate(w / 2, h / 2);
        ctx.rotate((tiltAngle * Math.PI) / 180);

        const totalWidth = Math.max(w, h) * 2.5;
        const barWidth = totalWidth / barCount;
        const offset = (time * 30) % barWidth;

        const currentIsDark = isDarkRef.current;

        for (let i = -1; i <= barCount + 1; i++) {
          const x = -totalWidth / 2 + i * barWidth + offset;

          const grad = ctx.createLinearGradient(x, -h, x + barWidth, h);
          if (currentIsDark) {
            grad.addColorStop(0, "rgba(198, 102, 244, 0.9)");
            grad.addColorStop(0.5, "rgba(99, 102, 241, 0.85)");
            grad.addColorStop(1, "rgba(27, 16, 34, 1.0)");
          } else {
            grad.addColorStop(0, "rgba(244, 237, 248, 1.0)");
            grad.addColorStop(0.5, "rgba(215, 190, 245, 0.9)");
            grad.addColorStop(1, "rgba(180, 220, 250, 0.9)");
          }

          ctx.fillStyle = grad;
          ctx.fillRect(x, -h * 1.5, barWidth, h * 3);

          const shadowWidthFactor =
            0.15 + 0.25 * (0.5 + 0.5 * Math.sin(time * 0.8 + i));
          const shadowAlpha =
            0.03 + 0.05 * (0.5 + 0.5 * Math.sin(time * 0.6 + i * 1.5));
          const shadowGrad = ctx.createLinearGradient(
            x,
            0,
            x + barWidth * shadowWidthFactor,
            0
          );
          shadowGrad.addColorStop(
            0,
            currentIsDark
              ? `rgba(10, 5, 16, ${shadowAlpha})`
              : `rgba(180, 150, 200, ${shadowAlpha * 1.5})`
          );
          shadowGrad.addColorStop(1, "rgba(0, 0, 0, 0)");

          ctx.fillStyle = shadowGrad;
          ctx.fillRect(x, -h * 1.5, barWidth * shadowWidthFactor, h * 3);
        }
        ctx.restore();

        animId = requestAnimationFrame(drawFallback);
      };
      drawFallback();
      return () => cancelAnimationFrame(animId);
    }

    // --- WebGL Shader Setup ---
    const vsSource = `
      attribute vec2 a_position;
      varying vec2 v_uv;
      void main() {
        v_uv = a_position * 0.5 + 0.5;
        gl_Position = vec4(a_position, 0.0, 1.0);
      }
    `;

    const fsSource = `
      precision highp float;
      varying vec2 v_uv;
      uniform float u_time;
      uniform vec2 u_resolution;
      uniform float u_intensity;
      uniform float u_tiltAngleRad;
      uniform float u_barCount;
      uniform float u_prevIsDark;
      uniform float u_targetIsDark;
      uniform float u_transitionProgress; // 0.0 to 1.0 expanding radial wave

      // Simplex 2D noise helpers
      vec3 permute(vec3 x) { return mod(((x*34.0)+1.0)*x, 289.0); }

      float snoise(vec2 v){
        const vec4 C = vec4(0.211324865405187, 0.366025403784439,
                 -0.577350269189626, 0.024390243902439);
        vec2 i  = floor(v + dot(v, C.yy) );
        vec2 x0 = v -   i + dot(i, C.xx);
        vec2 i1;
        i1 = (x0.x > x0.y) ? vec2(1.0, 0.0) : vec2(0.0, 1.0);
        vec4 x12 = x0.xyxy + C.xxzz;
        x12.xy -= i1;
        i = mod(i, 289.0);
        vec3 p = permute( permute( i.y + vec3(0.0, i1.y, 1.0 ))
        + i.x + vec3(0.0, i1.x, 1.0 ));
        vec3 m = max(0.5 - vec3(dot(x0,x0), dot(x12.xy,x12.xy), dot(x12.zw,x12.zw)), 0.0);
        m = m*m ;
        m = m*m ;
        vec3 x = 2.0 * fract(p * C.www) - 1.0;
        vec3 h = abs(x) - 0.5;
        vec3 ox = floor(x + 0.5);
        vec3 a0 = x - ox;
        m *= 1.79284291400159 - 0.85373472095314 * ( a0*a0 + h*h );
        vec3 g;
        g.x  = a0.x  * x0.x  + h.x  * x0.y;
        g.yz = a0.yz * x12.xz + h.yz * x12.yw;
        return 130.0 * dot(m, g);
      }

      void main() {
        // Aspect ratio correction
        vec2 st = (gl_FragCoord.xy - 0.5 * u_resolution.xy) / min(u_resolution.x, u_resolution.y);

        // Rotate space by tilt angle
        float cosA = cos(u_tiltAngleRad);
        float sinA = sin(u_tiltAngleRad);
        vec2 rotSt = vec2(
          st.x * cosA - st.y * sinA,
          st.x * sinA + st.y * cosA
        );

        float t = u_time * 0.2;

        // PROCEDURAL RADIAL GLOW COLOR PROPAGATION FROM CENTER
        vec2 centerUv = (gl_FragCoord.xy - 0.5 * u_resolution.xy) / max(u_resolution.x, u_resolution.y);
        float distFromCenter = length(centerUv);

        // Add organic noise ripple to the expanding wave front
        float waveNoise = 0.04 * snoise(centerUv * 3.5 + t * 0.5);
        float distortedDist = distFromCenter + waveNoise;

        // Expanding radial wave front radius (from 0.0 at center to 1.65 at corners)
        float waveRadius = u_transitionProgress * 1.65;

        // waveMask: 1.0 INSIDE expanding wave (NEW target theme), 0.0 OUTSIDE expanding wave (OLD theme)
        float waveMask = 1.0 - smoothstep(waveRadius - 0.10, waveRadius + 0.05, distortedDist);
        if (u_transitionProgress >= 1.0) {
          waveMask = 1.0;
        }

        // Luminous glowing ring traveling right on the wave edge as it spreads outward
        float glowEdge = abs(distortedDist - waveRadius);
        float glowRing = (1.0 - smoothstep(0.0, 0.18, glowEdge)) * step(distortedDist, waveRadius + 0.18);
        glowRing *= smoothstep(0.0, 0.08, u_transitionProgress) * (1.0 - smoothstep(0.92, 1.0, u_transitionProgress));

        // Effective theme mix factor for this exact pixel:
        // Outside wave: u_prevIsDark (OLD theme)
        // Inside wave: u_targetIsDark (NEW theme)
        float currentDarkThemeFactor = mix(u_prevIsDark, u_targetIsDark, waveMask);

        // CONTINUOUS MOVING BARS
        float barPos = rotSt.x * u_barCount + u_time * 0.22;
        float barIndex = floor(barPos);
        float barFraction = fract(barPos);

        // FEATHERED SUBTLE SHADOW
        float shadowNoise = snoise(vec2(barIndex * 0.6 + rotSt.y * 0.5, t * 0.3));
        float shadowWidth = mix(0.25, 0.55, 0.5 + 0.5 * shadowNoise);
        float shadowMinFactor = mix(0.85, 0.98, 0.5 + 0.5 * sin(t * 0.5 + barIndex * 1.2));

        float seamRipple = 0.04 * snoise(rotSt * 1.0 + vec2(t * 0.2, barIndex));
        float warpedFraction = barFraction + seamRipple;

        float shadowOnNextBar = smoothstep(-0.1, shadowWidth, warpedFraction);
        float shadowFactor = mix(shadowMinFactor, 1.0, shadowOnNextBar);

        float highlightWidth = mix(0.90, 0.98, 0.5 + 0.5 * shadowNoise);
        float edgeHighlight = smoothstep(highlightWidth, 1.0, barFraction) * mix(0.04, 0.12, shadowNoise * 0.5 + 0.5);

        // Soft noise scale for ambient fluid gradients
        vec2 warpSt = rotSt * 0.75;
        vec2 q = vec2(
          snoise(warpSt + vec2(barIndex * 0.4, t * 0.2)),
          snoise(warpSt + vec2(t * 0.15, barIndex * 0.3))
        );

        vec2 r = vec2(
          snoise(warpSt + 0.6 * q + vec2(2.1, 7.4) + 0.08 * t),
          snoise(warpSt + 0.6 * q + vec2(6.2, 3.1) + 0.06 * t)
        );

        float f = snoise(warpSt + 0.5 * r + t * 0.15);

        // Theme Palettes: Dark Theme vs Light Theme
        vec3 darkPlum    = vec3(0.10, 0.06, 0.14);
        vec3 darkPurple  = vec3(0.776, 0.400, 0.956); // #c666f4
        vec3 darkIndigo  = vec3(0.388, 0.400, 0.945); // #6366f1
        vec3 darkCyan    = vec3(0.024, 0.714, 0.831); // #06b6d4
        vec3 darkMagenta = vec3(0.878, 0.337, 0.992); // #e056fd

        // Vibrant light theme background palette (#f4edf8 base)
        vec3 lightPlum    = vec3(0.956, 0.929, 0.972); // #f4edf8
        vec3 lightPurple  = vec3(0.850, 0.550, 0.960);
        vec3 lightIndigo  = vec3(0.650, 0.680, 0.960);
        vec3 lightCyan    = vec3(0.420, 0.800, 0.920);
        vec3 lightMagenta = vec3(0.920, 0.550, 0.900);

        // Smoothly blend theme color tokens based on currentDarkThemeFactor
        vec3 colorPlum    = mix(lightPlum, darkPlum, currentDarkThemeFactor);
        vec3 colorPurple  = mix(lightPurple, darkPurple, currentDarkThemeFactor);
        vec3 colorIndigo  = mix(lightIndigo, darkIndigo, currentDarkThemeFactor);
        vec3 colorCyan    = mix(lightCyan, darkCyan, currentDarkThemeFactor);
        vec3 colorMagenta = mix(lightMagenta, darkMagenta, currentDarkThemeFactor);

        // Softened color transitions inside moving bars
        vec3 barColor = mix(colorPlum, colorIndigo, clamp(f * 1.5 + 0.2, 0.0, 1.0));
        barColor = mix(barColor, colorPurple, clamp(length(q) * 0.8, 0.0, 1.0) * 0.6);
        barColor = mix(barColor, colorCyan, clamp(length(r.x) * 0.8, 0.0, 1.0) * 0.35);
        barColor = mix(barColor, colorMagenta, clamp((f + 0.5) * 0.8, 0.0, 1.0) * 0.35);

        // Subtle bar-to-bar tone variation
        float barShift = snoise(vec2(barIndex * 0.8, 0.0)) * 0.08;
        barColor += vec3(barShift * 0.05, barShift * 0.03, barShift * 0.1);

        // Apply drop shadow & edge highlight
        barColor *= shadowFactor;
        barColor += colorPurple * edgeHighlight;

        // Vignette factor for soft corners
        vec2 vignetteUv = (v_uv - vec2(0.5));
        float vignette = 1.0 - smoothstep(0.4, 1.1, length(vignetteUv));

        // Blend solid base plum color with animated bar colors
        vec3 finalColor = mix(colorPlum, barColor, (0.75 + 0.25 * f) * u_intensity);
        finalColor *= (0.88 + 0.12 * vignette);

        // Radiant glow color sweeping with the wave
        vec3 lightGlowColor = vec3(0.98, 0.82, 1.0);  // radiant glowing lilac-gold for light mode
        vec3 darkGlowColor  = vec3(0.40, 0.85, 1.0);  // radiant electric cyan for dark mode
        vec3 glowColor = mix(lightGlowColor, darkGlowColor, u_targetIsDark);

        // Add luminous radial glow ring right on the expanding wave front
        finalColor += glowColor * glowRing * 0.75;

        // Fully opaque canvas output (prevents HTML body background from bleeding through prematurely!)
        gl_FragColor = vec4(finalColor, 1.0);
      }
    `;

    const createShader = (
      gl: WebGLRenderingContext,
      type: number,
      source: string
    ) => {
      const shader = gl.createShader(type);
      if (!shader) return null;
      gl.shaderSource(shader, source);
      gl.compileShader(shader);
      if (!gl.getShaderParameter(shader, gl.COMPILE_STATUS)) {
        console.error("Shader compile error:", gl.getShaderInfoLog(shader));
        gl.deleteShader(shader);
        return null;
      }
      return shader;
    };

    const vertexShader = createShader(gl, gl.VERTEX_SHADER, vsSource);
    const fragmentShader = createShader(gl, gl.FRAGMENT_SHADER, fsSource);
    if (!vertexShader || !fragmentShader) return;

    const program = gl.createProgram();
    if (!program) return;
    gl.attachShader(program, vertexShader);
    gl.attachShader(program, fragmentShader);
    gl.linkProgram(program);

    if (!gl.getProgramParameter(program, gl.LINK_STATUS)) {
      console.error("Program link error:", gl.getProgramInfoLog(program));
      return;
    }

    gl.useProgram(program);

    // Full screen quad buffer
    const positionBuffer = gl.createBuffer();
    gl.bindBuffer(gl.ARRAY_BUFFER, positionBuffer);
    gl.bufferData(
      gl.ARRAY_BUFFER,
      new Float32Array([-1, -1, 1, -1, -1, 1, -1, 1, 1, -1, 1, 1]),
      gl.STATIC_DRAW
    );

    const positionLocation = gl.getAttribLocation(program, "a_position");
    gl.enableVertexAttribArray(positionLocation);
    gl.vertexAttribPointer(positionLocation, 2, gl.FLOAT, false, 0, 0);

    const timeLocation = gl.getUniformLocation(program, "u_time");
    const resolutionLocation = gl.getUniformLocation(program, "u_resolution");
    const intensityLocation = gl.getUniformLocation(program, "u_intensity");
    const tiltAngleLocation = gl.getUniformLocation(program, "u_tiltAngleRad");
    const barCountLocation = gl.getUniformLocation(program, "u_barCount");
    const prevIsDarkLocation = gl.getUniformLocation(program, "u_prevIsDark");
    const targetIsDarkLocation = gl.getUniformLocation(program, "u_targetIsDark");
    const transitionProgressLocation = gl.getUniformLocation(
      program,
      "u_transitionProgress"
    );

    const resize = () => {
      if (!canvas || !gl) return;
      const dpr = Math.min(window.devicePixelRatio || 1, 2);
      const displayWidth =
        canvas.parentElement?.clientWidth || window.innerWidth;
      const displayHeight =
        canvas.parentElement?.clientHeight || window.innerHeight;

      if (
        canvas.width !== displayWidth * dpr ||
        canvas.height !== displayHeight * dpr
      ) {
        canvas.width = displayWidth * dpr;
        canvas.height = displayHeight * dpr;
        gl.viewport(0, 0, canvas.width, canvas.height);
      }
    };

    window.addEventListener("resize", resize);
    resize();

    gl.enable(gl.BLEND);
    gl.blendFunc(gl.SRC_ALPHA, gl.ONE_MINUS_SRC_ALPHA);

    let animationFrameId: number;
    let startTime = performance.now();

    const render = (now: number) => {
      if (!gl || !canvas) return;
      const elapsedTime = (now - startTime) * 0.001 * speed;

      // Calculate radial wave transition progress (1.4 second duration)
      const transitionDurationMs = 1400.0;
      const timeSinceTransition = now - transitionStartRef.current;
      const transitionProgress = Math.min(
        1.0,
        Math.max(0.0, timeSinceTransition / transitionDurationMs)
      );

      const targetIsDark = isDarkRef.current ? 1.0 : 0.0;
      const prevIsDark = prevIsDarkRef.current ? 1.0 : 0.0;

      gl.uniform1f(timeLocation, elapsedTime);
      gl.uniform2f(resolutionLocation, canvas.width, canvas.height);
      gl.uniform1f(intensityLocation, intensity);
      gl.uniform1f(tiltAngleLocation, (tiltAngle * Math.PI) / 180);
      gl.uniform1f(barCountLocation, barCount);
      gl.uniform1f(prevIsDarkLocation, prevIsDark);
      gl.uniform1f(targetIsDarkLocation, targetIsDark);
      gl.uniform1f(transitionProgressLocation, transitionProgress);

      gl.clearColor(0.0, 0.0, 0.0, 1.0);
      gl.clear(gl.COLOR_BUFFER_BIT);

      gl.drawArrays(gl.TRIANGLES, 0, 6);

      animationFrameId = requestAnimationFrame(render);
    };

    animationFrameId = requestAnimationFrame(render);

    return () => {
      window.removeEventListener("resize", resize);
      cancelAnimationFrame(animationFrameId);
      if (gl && program) {
        gl.deleteProgram(program);
      }
    };
  }, [speed, intensity, tiltAngle, barCount]);

  return (
    <canvas
      ref={canvasRef}
      className={`pointer-events-none absolute inset-0 h-full w-full ${className}`}
      style={{ opacity: 1 }}
    />
  );
};

export default GradientShaderBackground;
