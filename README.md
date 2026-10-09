# KantEase
school purpose

For Access
host: "localhost",
user: "vinxo",
password: "Shoto12+_)",

mariadb -u vinxo -p

CREATE OR REPLACE VIEW users_view AS
SELECT user_code, full_name, email, role, created_at FROM users;

SELECT * FROM users_view;

pkill -f "node server.js"