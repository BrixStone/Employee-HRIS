import http from 'k6/http';
import { check, sleep } from 'k6';

export const options = {
  vus: 30,
  duration: '10s',
};

export default function () {
  //const response = http.get('http://localhost:8000/');
  const response = http.get('http://localhost:8000/authentication/login.php');

  check(response, {
    'status is 200': (r) => r.status === 200,
  });

  sleep(1);
}

// to test loadtesting run this =  & "C:\Program Files\k6\k6.exe" run .\loadtest.js