
	function linkFormatter(value, row, index){
		return decodeHTML(value);
	}

	function decodeHTML(data) {
		var textArea = document.createElement('textarea');
		textArea.innerHTML = data;
		return textArea.value;
	}

