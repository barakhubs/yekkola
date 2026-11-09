// Enrolled students starting lessons: playback token, then the heartbeat a player sends while it streams.
import { check, sleep } from "k6";
import { data, pick, send, stages, student, thresholds } from "./lib.js";

export const options = { stages: stages(), thresholds };

export default function () {
  const lessons = data().free_course.media_lessons;
  if (!lessons.length) throw new Error("The fixture course has no video or audio lessons.");
  const h = student();
  const lesson = pick(lessons);

  // Tokens without a registered device count as web streams (one at a time per account), as in a browser.
  const token = send("POST", `/lessons/${lesson}/playback`, {}, h, "/lessons/{uid}/playback");
  check(token, { "playback token": (r) => r.status === 200 || r.status === 201 });
  const session = token.json("data.stream.session_id");
  sleep(15);

  if (session) {
    const beat = send(
      "POST",
      `/lessons/${lesson}/playback/heartbeat`,
      { session_id: session },
      h,
      "/lessons/{uid}/playback/heartbeat",
    );
    check(beat, { heartbeat: (r) => r.status === 200 });
  }
  sleep(15);
}
