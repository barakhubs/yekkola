import 'dart:async';
import 'dart:math';

import 'package:flutter/material.dart';

/// The per-viewer watermark over the player (PRD-07 FR-04): name + masked phone, semi-transparent, moving to
/// a new spot every 30–60 s. A deterrent against screen recording, not forensic tracing. Ignores touches.
class ViewerOverlay extends StatefulWidget {
  const ViewerOverlay({super.key, required this.text, this.random});

  final String text;
  final Random? random;

  @override
  State<ViewerOverlay> createState() => _ViewerOverlayState();
}

class _ViewerOverlayState extends State<ViewerOverlay> {
  late final Random _random = widget.random ?? Random();
  Alignment _alignment = Alignment.topLeft;
  Timer? _timer;

  @override
  void initState() {
    super.initState();
    _move();
  }

  void _move() {
    setState(() => _alignment = Alignment(_random.nextDouble() * 1.6 - 0.8, _random.nextDouble() * 1.6 - 0.8));
    _timer = Timer(Duration(seconds: 30 + _random.nextInt(31)), _move);
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return IgnorePointer(
      child: ExcludeSemantics(
        child: AnimatedAlign(
          alignment: _alignment,
          duration: const Duration(milliseconds: 600),
          child: Padding(
            padding: const EdgeInsets.all(12),
            child: Text(
              widget.text,
              style: const TextStyle(
                color: Color(0x8CFFFFFF),
                fontSize: 13,
                fontWeight: FontWeight.w600,
                shadows: [Shadow(color: Color(0x99000000), blurRadius: 3)],
              ),
            ),
          ),
        ),
      ),
    );
  }
}
