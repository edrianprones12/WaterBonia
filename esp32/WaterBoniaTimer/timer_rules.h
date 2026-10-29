#pragma once
#include <stdint.h>
namespace wb {
constexpr uint32_t hardMaxMs = 30000;
inline bool validDuration(uint32_t ms) { return ms >= 1000 && ms <= hardMaxMs; }
inline const char* stopReason(bool present, bool healthy, uint32_t elapsed, uint32_t duration) {
    if (!healthy) return "sensor_fault";
    if (!present) return "container_removed";
    if (elapsed > hardMaxMs) return "max_runtime_exceeded";
    if (elapsed >= duration) return "duration_elapsed";
    return nullptr;
}
}
