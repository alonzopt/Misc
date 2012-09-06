/*
 * Simple TCP/IP socket server.
 * Modified to handle the HTTP GET command by Alden Turner
 */

#include <sys/socket.h>
#include <arpa/inet.h>
#include <unistd.h>
#include <string.h>
#include <sys/time.h>
#include <sys/types.h>
#include <netdb.h>
#include <iostream>
#include <stdio.h>
#include <stdlib.h>
#include <errno.h>
#include <fstream>

using namespace std;

char *current_time_local_string()
{
  static char time_string[1000];
  time_t cur = time(NULL);
  struct tm *t = localtime(&cur);
  if (t == NULL) {
    perror("localtime()");
    exit(1);
  }
  strftime(time_string, sizeof(time_string),
           "%a, %d %b %Y %I:%M:%S %p", t);

  return time_string;
}

int MakeListener(int port)
/*  Creates a socket that listens for connections.
 *  Returns: the file descriptor of the listener on success, -1 on failure.
 */
{
  /*  Create a socket (i.e., communication endpoint)
   */
  int listener = socket(AF_INET, SOCK_STREAM, 0);
  if (listener<0) {
    cerr << "Couldn't create socket\n";
    return -1;
  }

  /*  Check that the port is within the valid range
   */
  if(port < 1024 || port > 65535){
  	cerr << "Port outside of valid range. Must be within 1024-65535.";
  	return -1;
  }
  
  /*  Name the socket
   *  (required before receiving connections)
   */
  struct sockaddr_in s1;
  bzero((char *) &s1, sizeof (s1));  /* They say to do this */
  s1.sin_family = AF_INET;
  s1.sin_addr.s_addr = INADDR_ANY;
  s1.sin_port = 0;  /* Have a port number assigned to us. */
  if (bind(listener, (sockaddr*)&s1, sizeof(s1))<0) {
    cerr << "Couldn't bind address to socket\n";
    return -1;
  }

  /* Get the host name. */
  char hostname[48];
  gethostname(hostname, 48);

  /* Get the name of the socket.
   * We only care about the port number, so that 
   * the clients know how to connect to our socket.
   */
  size_t length; 
  length = sizeof(s1);
  getsockname(listener, (sockaddr*) &s1, (socklen_t*) &length);

  cout << "\nListening on host: " << hostname;
  cout << "\t port: " << ntohs(s1.sin_port) << "\n\n";

  /* Start listening for connections. */
  if (listen(listener, 1) < 0) {
    cerr << "Couldn't listen().\n";
    return -1;
  }

  cout << "Ready for incoming connections\n";
  return listener;
}

/*
 *  Function to check if a given host name is forbidden from accessing the server
 *  
 *  Parameter name is the hostname to check
 *  
 *  Return values: true if the host is forbidden, false if it is not.
 */
bool forbidCheck(char* name){
	
	//open file stream of forbidden hosts
	ifstream forbid ("forbidden.txt");
	string cur;
	
	//File should be open, otherwise error
	if(forbid.is_open()){
		
		//check file for a matching hostname
		while(! forbid.eof()){
			getline (forbid,cur);
			
			if(cur.find(name) != string::npos){
				//a match is found, the host is forbidden
				return true;
			}
		}
	}
	else{
		cerr << "Unable to open forbidden.txt.\n All connections will be forbidden until this is fixed.";
		return true;
	}
	
	//current host not forbidden
	return false;
}

/*
 * Checks a string to see if it is a non-GET HTTP method
 *
 */
bool httpcmdcheck(string tocheck){
	string head ("HEAD");
	string put("PUT");
	string del ("DELETE");
	string options ("OPTIONS");
	string trace("TRACE");
	
	if(tocheck.compare(head) == true) return true;
	if(tocheck.compare(put) == true) return true;
	if(tocheck.compare(del) == true) return true;
	if(tocheck.compare(options) == true) return true;
	if(tocheck.compare(trace) == true) return true;
	
	return false;
}


/*
 *  Creates the message to be sent back to the client
 *
 *  First two parameters are the status of the message and the current time
 *  Last two parameters are for if the requested file was valid
 *
 *  Returns a string
 */
string makeMessage(string status, string timestamp, ifstream& doc/*, struct stat fstats*/){
	string returnstr = "HTTP/1.1 ";
	returnstr += status;
	returnstr += "\n Date: ";
	returnstr += timestamp;
	returnstr += "\n Server: WrittenQuickServ/1.0\n";
	
	//Everything checks out, include the requested file and it's information
	if(status.compare("200 OK") == 0){
		//get and format the time the file was modified
		/*char modtime[100];
  		struct tm *t = localtime(fstats.st_mtime);
  		strftime(modtime, sizeof(modtime),"%a, %d %b %Y %I:%M:%S %p", t);
		
		returnstr += "Last-Modified: ";
		returnstr += modtime;*/
		
		//add the file
		int length;
		doc.seekg(0,ios::end);
		length = doc.tellg();
		doc.seekg(0,ios::beg);
		
		returnstr += "\n Content-Length: ";
		returnstr += length;
		returnstr += "\n\r\n";
		
		char thefile [length];
		doc.read(thefile, length);
		
		returnstr += thefile;
	}
	
	returnstr += "\r\n";
	return returnstr;
}

/*
 *  Used to commit the most recent client/server interaction to the server log
 *  
 *  All parameters are various pieces of information to add to the log.
 */
void writelog(char* ip, char* hostname, int port, string timestamp, string wanted, string status){
	ofstream logger ("webserv.log");
	if (logger.is_open()){
		logger <<"Connection from host " << ip << " (" << hostname << "), port " << port << " on " << timestamp << ", file " << wanted << ", status " << status << "\n";
	}
	else{
		cerr << "Unable to write to log file.\n";
	}
}


/*
 *  Main function, contains infinite loop which listens for client interactions if it is able to set up a socket
 *
 */
int main(int argc, char * argv[])
{
  if(argc != 3){
  	cout << "Improper number of arguments. This program requires a starting directory and a port to listen to.\n";
  }
  
  //Make a listener
  int listener = MakeListener(atoi(argv[2]));
  //if listener creation was unsuccessful, exit
  if (listener < 0) return -1;
  
  //Main loop
  for (;;) {
    /* Wait for, and then accept an incoming connection. */
    cout << "Server waiting for connections\n";
    struct sockaddr_in s2;
    size_t length = sizeof(s2);
    int conn = accept(listener, (sockaddr*) &s2, (socklen_t*) &length);
	
    /*  We now have a connection to a client via file descriptor
     *  "conn". */

    // Get human-readable address of incoming host.
    struct hostent *peer = gethostbyaddr(&s2.sin_addr,
                                         sizeof(s2.sin_addr), AF_INET);
    char *peer_numeric = inet_ntoa(s2.sin_addr);
    char *peer_human = (peer == NULL) ? NULL : peer->h_name;
    int peer_port = ntohs(s2.sin_port);

	bool done = false;
	
	//check if the connection is valid
	if(peer_human == NULL || forbidCheck(peer_human)){
		cout << "Invalid connection from host " << peer_numeric << "\n";
		cout << "This will not be added to the log file.\n";
		done = true;
	}

	if(!done){
    	/* Get a message from the client. */
    	char data[128];
    	int msglen = read(conn, data, 128);
    	cout << "Server got " << msglen << " byte message: " << data << "\n";

		string unparsed = data;
		size_t loc = unparsed.find("\r\n");
	
		string manip = "";
		string methd = "none";
		string thing;
		string protcl = "none";
		string status = "none";
		string curtime = current_time_local_string();
	
		if(loc != string::npos){
			manip = unparsed.substr(0,loc - 1);
		}
	
		//Assuming 14 is the minimum number of chars needed to compose valid message
		//3 for method + 1 space + 1 for file + 1 space + 8 for "HTTP/x.x"
		if(manip.size() >= 14){
			//set methd
			loc = manip.find(" ");
			methd = manip.substr(0,loc-1);
		
			//set thing
			size_t startmiddle = manip.find_first_not_of(' ', loc);
			size_t endmiddle = manip.find(' ', startmiddle);
			thing = manip.substr(startmiddle, endmiddle-startmiddle);
		
			//set protcl
			protcl = manip.substr(manip.find_last_of(' ') + 1);
		
		
		}
		else{
			//first line too small to have everything necessary for a valid query
			status = "400 Bad Request";
			done = true;
		}
	
		//check that HTTP is being used
		if(!done && protcl.find("HTTP/") != 0){
			//Appearently the message insn't in HTTP
			status = "400 Bad Request";
			done = true;
		}
		
		//Check if the method is GET
		if(!done && methd.find("GET") != 0){
			//the method is not GET
			if(httpcmdcheck(methd)){
				//the method checks to be a valid, but unimplemented, HTTP command
				status = "501 Not Implemented";
				done = true;
			}
			else{
				//Unrecognized method
				status = "400 Bad Request";
				done = true;
			}
		}
		
		//See if the file can be found
		if(!done && access(thing.c_str(), F_OK) != 0){
			status = "404 Not Found";
			done = true;
		}
		
		//See if the file can be read
		if(!done && access(thing.c_str(), R_OK) != 0){
			status = "403 Forbidden";
			done = true;
		}
		
		ifstream toread;
		//struct stat stats;
		
		//Things seem ok, try to open file/get stats
		if(!done){
			toread.open(thing.c_str());
			if(!toread.is_open() /*|| stat(thing, &stats) != 0*/){
				status = "500 Internal Server Error";
				done = true;
			}
		}
		
		//Everything went ok
		if(!done){
			status = "200 OK";
		}
		
		string message = makeMessage(status, curtime, toread/*, stats*/);
		
    	// Write message
    	write(conn, message.c_str(), message.length());
    	
    	//log connection
    	writelog(peer_numeric, peer_human, atoi(argv[2]), curtime, thing, status); 
	
	}
    
    
    /* Close the connection on this end. */
    close(conn);
  }
  cout << "Exited main loop.\n This should not have happened.\n Something has obviously gone HORRIBLY wrong.\n";
  return 0;
}
