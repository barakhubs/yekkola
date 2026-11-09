// Paid checkout on the fake gateway: quote, order, start a mobile-money payment, poll the waiting screen.
// Each student may start 10 payments an hour (rate limit), so keep VUS × iterations within that, or prepare more
// students than virtual users.
import { check, sleep } from "k6";
import { data, get, pick, send, stages, student, thresholds, uuid } from "./lib.js";

export const options = { stages: stages(), thresholds };

export default function () {
  const h = student();
  const course = data().paid_course.id;

  // 422 once the student owns the course (an earlier iteration or run).
  const quote = send("POST", "/checkout/quote", { course_ids: [course] }, h);
  check(quote, { quote: (r) => r.status === 200 || r.status === 422 });
  if (quote.status === 422) return sleep(5);
  sleep(2);

  const order = send("POST", "/orders", { course_ids: [course] }, { ...h, "Idempotency-Key": uuid() });
  check(order, { order: (r) => r.status === 201 || r.status === 200 || r.status === 422 });
  const id = order.json("data.id");
  if (!id) return sleep(5);

  const payment = send(
    "POST",
    `/orders/${id}/payments`,
    { rail: pick(["orange", "airtel", "mpesa"]) },
    { ...h, "Idempotency-Key": uuid() },
    "/orders/{id}/payments",
  );
  check(payment, { payment: (r) => r.status < 300 });

  for (let i = 0; i < 5; i++) {
    sleep(3);
    const status = get(`/orders/${id}`, h, "/orders/{id}");
    check(status, { "order status": (r) => r.status === 200 });
    if (!["pending", "awaiting_payment"].includes(status.json("data.status"))) break;
  }
  sleep(10);
}
