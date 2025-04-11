const http = require("http");
const url = require("url"); // Import the url module
const mysql = require("mysql");
const moment = require("moment");

const port = 8082;

const connection = mysql.createConnection({
  // host: '108.167.172.143', // Replace with your MySQL host
  // user: 'ghebalon_p8jHR', // Replace with your MySQL username
  // password: '!7duf_7YvQmN', // Replace with your MySQL password
  // database: 'ghebalon_p8_juanhr' // Replace with your database name

  host: "localhost", // Replace with your MySQL host
  user: "root", // Replace with your MySQL username
  password: "", // Replace with your MySQL password
  database: "p8_juanhr_db", // Replace with your database name
});

const server = http.createServer((req, res) => {
  // Log some information about the request
  console.log(`${req.method} request received at ${req.url}`);

  // Parse the URL to access query parameters
  const parsedUrl = url.parse(req.url, true); // Set second argument to true to parse as object

  // Check if there are any query parameters
  if (parsedUrl.query) {
    console.log("Query parameters:", parsedUrl.query);
    for (const paramName in parsedUrl.query) {
      console.log(`${paramName}: ${parsedUrl.query[paramName]}`);
    }
  } else {
    console.log("No query parameters found.");
  }

  let rawData = "";
  let data = [];

  req.on("data", (chunk) => {
    // console.log('chunk', chunk);
    rawData += chunk;
    dataChunk = chunk.toString().split("\n").slice(0, -1);
    // console.log('dataChunk', dataChunk);
    dataChunk.forEach((splitChunk) => {
      // console.log('splitChunk', splitChunk);
      tabSplitChunk = splitChunk.split("\t");
      // console.log('tabSplitChunk', tabSplitChunk);
      data.push(tabSplitChunk);
    });
  });

  req.on("end", () => {
    // // Set header
    res.setHeader("Content-Type", "text/plain");
    const decodedString = rawData.toString(); // Decode as UTF-8 string
    console.log("Decoded UTF-8 data:", decodedString);
    console.log("Data chunk:", data);
    res.statusCode = 200;
    res.end("OK");
    if (!("SN" in parsedUrl.query)) {
      res.statusCode = 200;
      console.error("Error on ParseURL");
      res.end("ERROR");
      return;
    }
    const sql = `SELECT * FROM tbl_emp_device_registration WHERE deviceSerialID = '${parsedUrl.query["SN"]}'`; // Replace with your SQL query
    connection.query(sql, (err, results) => {
      if (err) {
        console.error("Error executing query:", err);
      }
      // console.log('Query results:', results); // Array of objects representing rows
      if (results.length <= 0) {
        console.log("Serial number is not valid.");
        res.statusCode = 200;
        res.end("ERROR");
        return;
      }
      console.log("Serial number is valid.");
      if ("table" in parsedUrl.query && parsedUrl.query["table"] === "ATTLOG") {
        console.log("Table is ATTLOG");
        data.forEach((attendanceData) => {
          const formattedDate = moment(attendanceData[1]).format("YYYY-MM-DD");
          const formattedTime = moment(attendanceData[1]).format("HH:mm:ss");
          const formattedLogType = parseInt(attendanceData[2]);
          const sql = `SELECT * FROM tbl_emp_device_registration WHERE deviceSerialID = '${parsedUrl.query["SN"]}' AND userID = '${attendanceData[0]}'`; // Replace with your SQL query
          connection.query(sql, (err, results) => {
            if (results.length <= 0) {
              console.log(
                `User does not exist. User ID: ${attendanceData[0]}, SN: ${parsedUrl.query["SN"]}`
              );
              res.statusCode = 200;
              res.end("ERROR");
              return;
            }
            const tableName = "tbl_dtr";
            const columns = [
              "dtrID",
              "employeeID",
              "dtrDate",
              "logTime",
              "type",
            ];
            const values = [
              0,
              results[0].employeeID,
              formattedDate,
              formattedTime,
              formattedLogType,
            ];
            const sql = `INSERT INTO ${tableName} (${columns.join(
              ", "
            )}) VALUES (?, ?, ?, ?, ?)`;
            connection.query(sql, values, (err, results) => {
              if (err) {
                console.error("Error executing query:", err);
                res.statusCode = 200;
                res.end("ERROR");
                return;
              }
              console.log("Insertion success. Result: ", results);
            });
          });
        });
        console.log("end of dtr loop");
        res.statusCode = 200;
        res.end("OK");
        return;
      } else {
        console.log("Request not for attendance logs");
        res.statusCode = 200;
        res.end("OK");
        return;
      }
    });
  });
});

connection.connect((err) => {
  if (err) {
    console.error("Error connecting to MySQL:", err);
    process.exit(1);
  }

  console.log("Connected to MySQL database");

  server.listen(port, () => {
    console.log(`Server listening on port ${port}`);
  });
});
