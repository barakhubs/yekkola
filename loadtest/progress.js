// Progress sync: the web player saves every 15 s; the mobile app sends batches when it comes back online.
import { check, sleep } from "k6";
import { data, send, stages, student, thresholds } from "./lib.js";

export const options = { stages: stages(), thresholds };

export default function () {
  const lessons = data().free_course.media_lessons;
  const h = student();
  const mobileBatch = Math.random() < 0.2;
  const items = (mobileBatch ? lessons.slice(0, 20) : [lessons[__ITER % lessons.length]]).map((uid) => ({
    lesson_uid: uid,
    position_seconds: (__ITER * 15) % 3600,
    completed: mobileBatch && Math.random() < 0.5,
    client_updated_at: new Date().toISOString(),
  }));

  const res = send(
    "PUT",
    "/me/progress",
    { items },
    h,
    mobileBatch ? "/me/progress (batch)" : "/me/progress",
  );
  check(res, { "progress saved": (r) => r.status === 200 });
  sleep(15);
}
