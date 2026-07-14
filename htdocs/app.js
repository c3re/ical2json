let previewData = [];
let getData = function () {
  let data = {};
  data.url = document.getElementById("url").value;
  data.start = document.getElementById("start").value;
  data.end = document.getElementById("end").value;
  data.maxitems = document.getElementById("maxitems").value;
  for (let key in data) {
    if (data[key] == "") {
      delete data[key];
    }
  }
  return data;
};

document.getElementById("form").onsubmit = function () {
  // Get data from form
  let data = getData();
  // Send data to server as url form encoded
  let xhr = new XMLHttpRequest();
  let outputEl = document.getElementById("preview");
  outputEl.innerHTML = "Loading...";
  outputEl.classList.add("loading");
  outputEl.classList.remove("error");
  let url = "./api/?" + new URLSearchParams(data).toString();
  xhr.open("get", url);
  xhr.onload = function () {
    if (xhr.status == 200) {
      outputEl.classList.remove("loading");
      outputEl.classList.remove("error");
    } else {
      outputEl.classList.remove("loading");
      outputEl.classList.add("error");
    }
    document.getElementById("preview").innerHTML = xhr.responseText;
    previewData = JSON.parse(xhr.responseText);
  };
  xhr.send();

  document.getElementById("previewUrl").value =
    window.location.protocol + "//" + window.location.host + url;
  return false;
};
let start = document.getElementById("start");
start.onchange = function () {
  let val = this.value;
  if (val == "") {
    val = start.getAttribute("placeholder");
  }
  let xhr = new XMLHttpRequest();
  xhr.open(
    "get",
    "/strtotime/?" +
      new URLSearchParams({ date: val, format: "Y-m-d" }).toString()
  );
  xhr.onload = function () {
    if (xhr.status == 200) {
      document.querySelector(".previewStart").textContent = xhr.responseText;
    }
  };
  xhr.send();
};
start.onkeyup = start.onchange;
start.onchange();

let end = document.getElementById("end");
end.onchange = function () {
  let val = this.value;
  if (val == "") {
    val = end.getAttribute("placeholder");
  }
  let xhr = new XMLHttpRequest();
  xhr.open(
    "get",
    "/strtotime/?" +
      new URLSearchParams({ date: val, format: "Y-m-d" }).toString()
  );
  xhr.onload = function () {
    if (xhr.status == 200) {
      document.querySelector(".previewEnd").textContent = xhr.responseText;
    }
  };
  xhr.send();
};
end.onkeyup = end.onchange;
end.onchange();
